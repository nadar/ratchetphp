<?php

namespace Ratchet\WebSocket;

use Ratchet\Mock\Connection;
use Ratchet\RFC6455\Messaging\Frame;
use PHPUnit\Framework\TestCase;

/**
 * @covers Ratchet\WebSocket\WsConnection
 */
class WsConnectionTest extends TestCase
{
    /**
     * @var Connection
     */
    protected $mock;

    /**
     * @var WsConnection
     */
    protected $conn;

    public function setUp(): void
    {
        $this->mock = new Connection();
        $this->mock->WebSocket = new \StdClass();
        $this->mock->WebSocket->closing = false;

        $this->conn = new WsConnection($this->mock);
    }

    public function testSendWrapsAStringInATextFrame()
    {
        $this->conn->send('Hello');

        $this->assertSame((new Frame('Hello'))->getContents(), $this->mock->last['send']);
    }

    public function testSendPassesADataInterfaceThroughUntouched()
    {
        $frame = new Frame('Ping', true, Frame::OP_PING);
        $this->conn->send($frame);

        $this->assertSame($frame->getContents(), $this->mock->last['send']);
    }

    public function testSendReturnsSelfForChaining()
    {
        $this->assertSame($this->conn, $this->conn->send('Hello'));
    }

    public function testSendIsANoOpWhenTheConnectionIsClosing()
    {
        $this->mock->WebSocket->closing = true;
        $this->conn->send('Hello');

        $this->assertSame('', $this->mock->last['send']);
    }

    public function testCloseSendsACloseFrameWithTheGivenCode()
    {
        $this->conn->close(1000);

        $this->assertSame(
            (new Frame(pack('n', 1000), true, Frame::OP_CLOSE))->getContents(),
            $this->mock->last['send']
        );
    }

    public function testCloseClosesTheUnderlyingConnectionAndFlipsTheClosingFlag()
    {
        $this->conn->close();

        $this->assertTrue($this->mock->last['close']);
        $this->assertTrue($this->mock->WebSocket->closing);
    }

    public function testCloseWithADataInterfaceSendsItVerbatim()
    {
        $frame = new Frame(pack('n', 1001), true, Frame::OP_CLOSE);
        $this->conn->close($frame);

        $this->assertSame($frame->getContents(), $this->mock->last['send']);
    }

    public function testASecondCloseIsANoOp()
    {
        $this->conn->close();

        // Reset the recorder so we can prove nothing else is written/closed.
        $this->mock->last['send']  = '';
        $this->mock->last['close'] = false;

        $this->conn->close();

        $this->assertSame('', $this->mock->last['send']);
        $this->assertFalse($this->mock->last['close']);
    }
}
