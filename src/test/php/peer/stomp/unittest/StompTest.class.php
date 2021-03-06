<?php namespace peer\stomp\unittest;

use lang\IllegalArgumentException;
use peer\stomp\frame\{AbortFrame, AckFrame, BeginFrame, CommitFrame, Frame, MessageFrame, NackFrame, ReceiptFrame, SendFrame, SubscribeFrame, UnsubscribeFrame};
use peer\stomp\{Connection, Destination, Exception};
use peer\{AuthenticationException, ProtocolException, URL};
use unittest\{Expect, Test};

/**
 * Tests STOMP protocol
 *
 * @see   http://stomp.github.com/stomp-specification-1.1.html#STOMP_Frames
 * @see   xp://peer.stomp.Connection
 */
class StompTest extends BaseTest {
  const PINGS = "\n\n\n\n";

  #[Test]
  public function connect() {
    $this->fixture->setResponseBytes("CONNECTED\n".
      "session-id:0xdeadbeef\n".
      "\n\0"
    );
    $this->fixture->connect();

    $this->assertEquals("CONNECT\n".
      "accept-version:1.0,1.1\n".
      "host:localhost\n".
      "login:user\n".
      "passcode:pass\n".
      "\n\0",
      $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function login_without_credentials() {
    $this->fixture= $this->newConnection(new URL('stomp://localhost/'));
    $this->fixture->setResponseBytes("CONNECTED\n".
      "version:1.0\n".
      "session-id:0xdeadbeef\n".
      "\n\0"
    );
    $this->fixture->connect();

    $this->assertEquals("CONNECT\n".
      "accept-version:1.0,1.1\n".
      "host:localhost\n".
      "\n\0",
      $this->fixture->readSentBytes()
    );
  }

  #[Test, Expect(AuthenticationException::class)]
  public function loginFailed() {
    $this->fixture->setResponseBytes("ERROR\n".
      "message: Invalid credentials\n".
      "\n\0"
    );
    $this->fixture->connect();
  }

  #[Test]
  public function connect_and_negotiate_version() {
    $this->fixture= $this->newConnection(new URL('stomp://user:pass@host?vhost=localhost&versions=1.0,1.1'));
    $this->fixture->setResponseBytes("CONNECTED\n".
      "session-id:0xdeadbeef\n".
      "version:1.1\n".
      "\n\0"
    );
    $this->fixture->connect();

    $this->assertEquals("CONNECT\n".
      "accept-version:1.0,1.1\n".
      "host:localhost\n".
      "login:user\n".
      "passcode:pass\n".
      "\n\0",
      $this->fixture->readSentBytes()
    );
  }

  #[Test, Expect(AuthenticationException::class)]
  public function connect_and_negotiate_version_but_fails() {
    $this->fixture= $this->newConnection(new URL('stomp://user:pass@host?vhost=localhost&versions=1.0,1.1'));
    $this->fixture->setResponseBytes("ERROR\n".
      "version:1.1\n".
      "content-type:text/plain\n".
      "\n".
      "Supported protocol versions are: 1.2".
      "\n\0"
    );
    $this->fixture->connect();

    $this->assertEquals("CONNECT\n".
      "accept-version:1.0,1.1\n".
      "host:localhost\n".
      "login:user\n".
      "passcode:pass\n".
      "\n\0",
      $this->fixture->readSentBytes()
    );
  }

  #[Test, Expect(['class' => IllegalArgumentException::class, 'withMessage' => '/Invalid protocol version/'])]
  public function connect_requires_valid_version() {
    $this->newConnection(new URL('stomp://user:pass@host?versions='))->connect();
  }

  #[Test]
  public function sendFrame() {
    $this->fixture->setResponseBytes("RECEIPT\n".
      "receipt-id:message-id\n".
      "\n\0"
    );

    $this->fixture->sendFrame(new SendFrame('/queue/a', 'my-data'));
    $this->assertEquals("SEND\n".
      "destination:/queue/a\n".
      "\nmy-data\0",
      $this->fixture->readSentBytes()
    );
    $response= $this->fixture->recvFrame();

    $this->assertInstanceOf(ReceiptFrame::class, $response);
  }

  #[Test]
  public function receiveError() {
    $this->fixture->setResponseBytes("ERROR\n".
      "message:Unknown command\n".
      "\n".
      "Line1\nLine2\0");

    $response= $this->fixture->recvFrame();
    $this->assertEquals('Unknown command', $response->getHeader('message'));
    $this->assertEquals("Line1\nLine2", $response->getBody());
  }

  #[Test]
  public function receiveErrorWithContentLengthGiven() {
    $this->fixture->setResponseBytes("ERROR\n".
      "message:Unknown command\n".
      "code:message:unknown\n".
      "content-length:11\n".
      "\n".
      "Line1\nLine2\0\n");

    $response= $this->fixture->recvFrame();
    $this->assertEquals('Unknown command', $response->getHeader('message'));
    $this->assertEquals('message:unknown', $response->getHeader('code'));
    $this->assertEquals("Line1\nLine2", $response->getBody());
  }

  #[Test, Expect(ProtocolException::class)]
  public function catchInvalidContentLength() {
    $this->fixture->setResponseBytes("ERROR\n".
      "message:Unknown command\n".
      "content-length:10\n".
      "\n".
      "Content longer that 10 bytes.\0"
    );
    $response= $this->fixture->recvFrame();
  }

  #[Test]
  public function recv_eats_any_empty_line_before_frame() {
    $this->fixture->setResponseBytes(
      self::PINGS.
      "RECEIPT\n".
      "message_id:12345\n".
      "\n\0"
    );

    $recvd= $this->fixture->recvFrame();
    $this->assertInstanceOf(ReceiptFrame::class, $recvd);
  }

  #[Test]
  public function recv_eats_any_empty_line_between_frames() {
    $this->fixture->setResponseBytes(
      "RECEIPT\n".
      "message_id:12345\n".
      "\n\0".
      self::PINGS.
      "MESSAGE\n".
      "message_id:12345\n".
      "\n\0"
    );

    $recvd= [];
    $recvd[]= $this->fixture->recvFrame();
    $recvd[]= $this->fixture->recvFrame();
    $this->assertInstanceOf(ReceiptFrame::class, $recvd[0]);
    $this->assertInstanceOf(MessageFrame::class, $recvd[1]);
  }

  #[Test, Expect(['class' => Exception::class, 'withMessage' => '/ACK received without/'])]
  public function receive_throws_exception_on_error_frame() {
    $this->fixture->setResponseBytes("ERROR\n".
      "message:ACK received without a subscription id for acknowledge!\n".
      "\n".
      "ACK received without a subscription id for acknowledge!".
      "\n\0"
    );

    $msg= $this->fixture->receive();
  }

  /**
   * Helper
   *
   * @param   peer.stomp.frame.Frame fram
   */
  protected function sendWithReceiptFrame(Frame $frame) {
    $this->fixture->setResponseBytes("RECEIPT\n".
      "receipt-id:message-id\n".
      "\n\0"
    );

    return $this->fixture->sendFrame($frame);
  }

  #[Test]
  public function subscribe() {
    $this->sendWithReceiptFrame(new SubscribeFrame('/queue/a'));
    $this->assertEquals("SUBSCRIBE\n".
      "destination:/queue/a\n".
      "ack:auto\n".
      "\n".
      "\0",
      $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function unsubscribe() {
    $this->sendWithReceiptFrame(new UnsubscribeFrame('/queue/a'));
    $this->assertEquals("UNSUBSCRIBE\n".
      "destination:/queue/a\n".
      "\n".
      "\0",
      $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function beginTransaction() {
    $this->sendWithReceiptFrame(new BeginFrame('my-transaction'));
    $this->assertEquals("BEGIN\n".
      "transaction:my-transaction\n\n\0"
      , $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function abortTransaction() {
    $this->sendWithReceiptFrame(new AbortFrame('my-transaction'));
    $this->assertEquals("ABORT\n".
      "transaction:my-transaction\n\n\0"
      , $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function commitTransaction() {
    $this->sendWithReceiptFrame(new CommitFrame('my-transaction'));
    $this->assertEquals("COMMIT\n".
      "transaction:my-transaction\n\n\0"
      , $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function ack() {
    $this->sendWithReceiptFrame(new AckFrame('0xefefef', '1x1x1x1x1x1'));
    $this->assertEquals("ACK\n".
      "message-id:0xefefef\n".
      "subscription:1x1x1x1x1x1\n".
      "\n\0"
      , $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function nack() {
    $this->sendWithReceiptFrame(new NackFrame('0xefefef', '0x0x0x0x0'));
    $this->assertEquals("NACK\n".
      "message-id:0xefefef\n".
      "subscription:0x0x0x0x0\n".
      "\n\0"
      , $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function ackWithinTransaction() {
    $this->sendWithReceiptFrame(new AckFrame('0xefefef', 'some-subscription', "some-transaction"));
    $this->assertEquals("ACK\n".
      "message-id:0xefefef\n".
      "subscription:some-subscription\n".
      "transaction:some-transaction\n".
      "\n\0"
      , $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function nackWithinTransaction() {
    $this->sendWithReceiptFrame(new NackFrame('0xefefef', 'some-subscription', "some-transaction"));
    $this->assertEquals("NACK\n".
      "message-id:0xefefef\n".
      "subscription:some-subscription\n".
      "transaction:some-transaction\n".
      "\n\0"
      , $this->fixture->readSentBytes()
    );
  }

  #[Test]
  public function disconnect() {
    $this->fixture->disconnect();

    $this->assertEquals("DISCONNECT\n\n\0", $this->fixture->readSentBytes());
  }

  #[Test]
  public function noTrailingEOL() {
    $this->fixture->setResponseBytes(
      "ERROR\n\nLine1\nLine2\0".
      "RECEIPT\nreceipt-id:77\n\n\0\n\n"
    );

    $response= $this->fixture->recvFrame();
    $receipt= $this->fixture->recvFrame();
    $this->assertEquals("Line1\nLine2", $response->getBody());
    $this->assertEquals('', $receipt->getBody());
  }

  #[Test]
  public function withContentLengthNoTrailingEOL() {
    $this->fixture->setResponseBytes(
      "ERROR\ncontent-length:11\n\nLine1\nLine2\0".
      "RECEIPT\nreceipt-id:77\n\n\0\n\n"
    );

    $response= $this->fixture->recvFrame();
    $receipt= $this->fixture->recvFrame();
    $this->assertEquals("Line1\nLine2", $response->getBody());
    $this->assertEquals('', $receipt->getBody());
  }

  #[Test]
  public function oneTrailingEOL() {
    $this->fixture->setResponseBytes(
      "ERROR\n\nLine1\nLine2\0\n".
      "RECEIPT\nreceipt-id:77\n\n\0\n\n"
    );

    $response= $this->fixture->recvFrame();
    $receipt= $this->fixture->recvFrame();
    $this->assertEquals("Line1\nLine2", $response->getBody());
    $this->assertEquals('', $receipt->getBody());
  }

  #[Test]
  public function withContentLengthOneTrailingEOL() {
    $this->fixture->setResponseBytes(
      "ERROR\ncontent-length:11\n\nLine1\nLine2\0\n".
      "RECEIPT\nreceipt-id:77\n\n\0\n\n"
    );

    $response= $this->fixture->recvFrame();
    $receipt= $this->fixture->recvFrame();
    $this->assertEquals("Line1\nLine2", $response->getBody());
    $this->assertEquals('', $receipt->getBody());
  }

  #[Test]
  public function twoTrailingEOLs() {
    $this->fixture->setResponseBytes(
      "ERROR\n\nLine1\nLine2\0\n\n".
      "RECEIPT\nreceipt-id:77\n\n\0\n\n"
    );

    $response= $this->fixture->recvFrame();
    $receipt= $this->fixture->recvFrame();
    $this->assertEquals("Line1\nLine2", $response->getBody());
    $this->assertEquals('', $receipt->getBody());
  }

  #[Test]
  public function withContentLengthTwoTrailingEOLs() {
    $this->fixture->setResponseBytes(
      "ERROR\ncontent-length:11\n\nLine1\nLine2\0\n\n".
      "RECEIPT\nreceipt-id:77\n\n\0\n\n"
    );

    $response= $this->fixture->recvFrame();
    $receipt= $this->fixture->recvFrame();
    $this->assertEquals("Line1\nLine2", $response->getBody());
    $this->assertEquals('', $receipt->getBody());
  }

  #[Test]
  public function acquire_destination() {
    $this->assertInstanceOf(Destination::class, $this->fixture->getDestination('/queue/unittest'));
  }

  #[Test]
  public function destination_holds_name() {
    $this->assertEquals(
      '/queue/unittest',
      $this->fixture->getDestination('/queue/unittest')->getName()
    );
  }

  #[Test]
  public function destination_holds_connection() {
    $this->assertEquals(
      $this->fixture,
      $this->fixture->getDestination('/queue/unittest')->getConnection()
    );
  }
}