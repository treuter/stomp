<?php namespace org\codehaus\stomp;

use util\log\Traceable;
use peer\Socket;
use peer\SocketInputStream;
use peer\SocketOutputStream;
use io\streams\MemoryOutputStream;
use io\streams\OutputStreamWriter;
use io\streams\StringReader;
use io\streams\StringWriter;

/**
 * Low level API to the STOMP protocol
 *
 * @see   http://stomp.codehaus.org/Protocol
 * @test  xp://org.codehaus.stomp.unittest.StompTest
 */
class StompConnection extends \lang\Object implements Traceable {
  protected
    $server = NULL,
    $port   = NULL;

  protected
    $socket = NULL,
    $in     = NULL,
    $out    = NULL,
    $subscriptions = array();

  protected
    $cat    = NULL;

  /**
   * Constructor
   *
   * @param   string server
   * @param   int port
   */
  public function __construct($server, $port) {
    $this->server= $server;
    $this->port= $port;
  }

  /**
   * Set trace
   *
   * @param   util.log.LogCategory cat
   */
  public function setTrace($cat) {
    $this->cat= $cat;
  }

  /**
   * Connect to server
   *
   */
  protected function _connect() {
    $this->socket= new Socket($this->server, $this->port);
    $this->socket->connect();

    $this->in= new StringReader(new SocketInputStream($this->socket));
    $this->out= new StringWriter(new SocketOutputStream($this->socket));
  }

  /**
   * Disconnect from server
   *
   */
  protected function _disconnect() {
    $this->out= NULL;
    $this->in= NULL;
    $this->socket->close();
  }

  /**
   * Receive next frame, nonblocking
   *
   * @param   double timeout default 0.2
   * @return  org.codehaus.stomp.frame.Frame or NULL
   */
  public function recvFrame($timeout= 0.2) {

    // Check whether we can read, before we actually read...
    if ($this->socket instanceof Socket && !$this->socket->canRead($timeout)) {
      $this->cat && $this->cat->debug($this->getClassName(), '<<<', '0 bytes - reading no frame.');
      return NULL;
    }

    $line= $this->in->readLine();
    $this->cat && $this->cat->debug($this->getClassName(), '<<<', 'Have "'.trim($line).'" command.');

    if (0 == strlen($line)) throw new \peer\ProtocolException('Expected frame token, got "'.\xp::stringOf($line).'"');

    $frame= \lang\XPClass::forName(sprintf('org.codehaus.stomp.frame.%sFrame', ucfirst(strtolower(trim($line)))))
      ->newInstance()
    ;
    $frame->fromWire($this->in);

    // According to the STOMP protocol, the NUL ("\0") delimiter may be followed
    // by any number of EOL ("\n") characters. Read them here but be careful not
    // to read across past a socket's current stream end!
    // FIXME: This conflicts with heart-beating, we might be swallowing that here
    // but not reacting correctly in other places!
    $c= '';
    while (
      ($this->socket instanceof Socket ? $this->socket->canRead(0.01) : $this->in->getStream()->available()) &&
      "\n" === ($c= $this->in->read(1))
    ) {
      // Skip
    }
    $f= $this->in->getClass()->getField('buf')->setAccessible(TRUE);
    $f->set($this->in, $c.$f->get($this->in));

    return $frame;
  }

  /**
   * Send a frame to server
   *
   */
  public function sendFrame(frame\Frame $frame) {

    // Trace
    if ($this->cat) {
      $mo= new MemoryOutputStream();
      $frame->write(new StringWriter($mo));

      $this->cat->debug($this->getClassName(), '>>>', $mo->getBytes());
    }

    $frame->write($this->out);

    if ($frame->requiresImmediateResponse()) {
      return $this->recvFrame();
    }

    return NULL;
  }

  /**
   * Connect to server with given username and password
   *
   * @param   string user
   * @param   string pass
   * @param   string[] protoVersions list of supported protocol versions default NULL
   * @return  bool
   * @throws  peer.AuthenticationException if login failed
   */
  public function connect($user, $pass, $host= NULL, $protoVersions= NULL) {
    $this->_connect();

    $frame= $this->sendFrame(new frame\LoginFrame($user, $pass, $host, $protoVersions));
    if (!$frame instanceof frame\Frame) {
      throw new \peer\ProtocolException('Did not receive frame, got: '.\xp::stringOf($frame));
    }
    if ($frame instanceof frame\ErrorFrame) {
      throw new \peer\AuthenticationException(
        'Could not establish connection to broker "'.$this->server.':'.$this->port.'": '.$frame->getBody(),
        $user, ''
      );
    }
    if (!$frame instanceof frame\ConnectedFrame) {
      throw new \peer\AuthenticationException(
        'Could not log in to stomp broker "'.$this->server.':'.$this->port.'": Got "'.$frame->command().'" frame',
        $user, ''
      );
    }

    $this->cat && $this->cat->debug($this->getClassName(), '~ Connected to server; server '.($frame->getProtocolVersion()
      ? 'chose protocol version '.$frame->getProtocolVersion()
      : 'did not indicate protocol version'
    ));

    return TRUE;
  }

  /**
   * Disconnect by sending disconnect frame
   *
   */
  public function disconnect() {

    // Bail out if not connected
    if (!$this->out instanceof OutputStreamWriter) return;

    // Send disconnect frame and exit
    create(new frame\DisconnectFrame())->write($this->out);
    $this->_disconnect();
  }

  /**
   * Begin server transaction
   *
   * @param   string transaction
   */
  public function begin(Transaction $transaction) {
    $transaction->begin($this);
    return $transaction;
  }

  /**
   * Send new message to destination
   *
   * @param   string destination
   * @param   string body
   * @param   [:string] headers
   */
  public function send($destination, $body, $headers= array()) {
    return $this->sendFrame(new frame\SendFrame($destination, $body, $headers));
  }

  /**
   * Create new subscription
   *
   * @param  org.codehaus.stomp.StompSubscription $subscription
   * @return org.codehaus.stomp.StompSubscription
   */
  public function subscribe(Subscription $subscription) {
    $subscription->subscribe($this);
    $this->subscriptions[$subscription->getId()]= $subscription;
    return $subscription;
  }

  public function unsubscribe(Subscription $subscription) {
    unset($this->subscriptions[$subscription->getId()]);
  }

  public function subscriptionById($id) {
    if (!isset($this->subscriptions[$id])) {
      throw new Exception('No such subscription: "'.$id.'"');
    }

    return $this->subscriptions[$id];
  }

  /**
   * Receive a message
   *
   * @param   double timeout default 0.2 pass NULL for no timeout
   * @return  org.codehaus.stomp.frame.Frame
   */
  public function receive($timeout= 0.2) {
    $frame= $this->recvFrame($timeout);

    if ($frame instanceof frame\MessageFrame) {
      $msg= new ReceivedMessage();
      $msg->withFrame($frame, $this);
      return $msg;
    }

    return $frame;
  }

  public function acquireDestination($name) {
    return new Destination($name, $this);
  }

  /**
   * Creates a string representation of this object
   *
   * @return  string
   */
  public function toString() {
    return $this->getClassName().'(->'.$this->server.':'.$this->port.')';
  }
}
