<?php

namespace Ratchet\WebSocket;

use GuzzleHttp\Psr7\Request;
use Ratchet\ComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface as DataComponentInterface;
use Ratchet\Mock\Connection;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\LoopInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers Ratchet\WebSocket\WsServer
 */
class WsServerTest extends TestCase
{
    /**
     * Build a valid RFC6455 upgrade request so the handshake negotiator
     * returns a 101 response and the connection is registered.
     *
     * @param array $extraHeaders
     * @return Request
     */
    protected function newUpgradeRequest(array $extraHeaders = [])
    {
        return new Request('GET', 'ws://localhost/', array_merge([
            'Host'                  => 'localhost',
            'Upgrade'               => 'websocket',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Key'     => base64_encode('0123456789abcdef'),
            'Sec-WebSocket-Version' => '13',
        ], $extraHeaders), null, '1.1');
    }

    /**
     * Open a real WebSocket connection through the server and return the
     * decorated mock connection so the caller can inspect what was written.
     *
     * @param WsServer $server
     * @return Connection
     */
    protected function openConnection(WsServer $server)
    {
        $conn = new Connection();
        $server->onOpen($conn, $this->newUpgradeRequest());

        return $conn;
    }

    /**
     * Build a client (masked) frame the way a browser would send it.
     *
     * @param string $payload
     * @param int    $opcode
     * @return string
     */
    protected function clientFrame($payload, $opcode = Frame::OP_TEXT)
    {
        return (new Frame($payload, true, $opcode))->maskPayload()->getContents();
    }

    public function testConstructAcceptsAWebSocketMessageComponent()
    {
        $component = $this->createMock(MessageComponentInterface::class);

        $this->assertInstanceOf(WsServer::class, new WsServer($component));
    }

    public function testConstructAcceptsAPlainMessageComponent()
    {
        $component = $this->createMock(DataComponentInterface::class);

        $this->assertInstanceOf(WsServer::class, new WsServer($component));
    }

    public function testConstructRejectsAComponentThatIsNeitherKind()
    {
        $this->expectException(\UnexpectedValueException::class);

        new WsServer($this->createMock(ComponentInterface::class));
    }

    /**
     * Regression test: constructing a WsServer without an explicit PSR-17
     * response factory must not require an undeclared dependency. This path
     * is used by Ratchet\App::route() and the README chat example, so a
     * missing factory class fatals the most common usage.
     */
    public function testConstructWithoutAResponseFactoryDoesNotFatal()
    {
        $component = $this->createMock(MessageComponentInterface::class);

        // Would throw "Class ... not found" if the default factory is not
        // provided by a declared composer dependency.
        $this->assertInstanceOf(WsServer::class, new WsServer($component));
    }

    public function testOnOpenWithoutARequestThrows()
    {
        $server = new WsServer($this->createMock(MessageComponentInterface::class));

        $this->expectException(\UnexpectedValueException::class);
        $server->onOpen(new Connection());
    }

    public function testSuccessfulHandshakeSendsA101AndOpensTheDelegate()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->once())
            ->method('onOpen')
            ->with($this->isInstanceOf(WsConnection::class));

        $server = new WsServer($component);
        $conn   = $this->openConnection($server);

        $this->assertStringContainsString('101', $conn->last['send']);
        $this->assertStringContainsStringIgnoringCase('upgrade', $conn->last['send']);
        $this->assertStringContainsString(\Ratchet\VERSION, $conn->last['send']);
    }

    public function testFailedHandshakeClosesTheConnectionAndDoesNotOpenTheDelegate()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->never())->method('onOpen');

        $server = new WsServer($component);

        $conn = new Connection();
        // A plain GET with no upgrade headers fails RFC6455 verification.
        $server->onOpen($conn, new Request('GET', 'http://localhost/', ['Host' => 'localhost'], null, '1.1'));

        $this->assertTrue($conn->last['close']);
    }

    public function testOnMessageBuffersFramesAndDeliversTheCoalescedMessage()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->once())
            ->method('onMessage')
            ->with(
                $this->isInstanceOf(WsConnection::class),
                $this->callback(fn ($msg) => (string) $msg === 'Hello World')
            );

        $server = new WsServer($component);
        $conn   = $this->openConnection($server);

        $server->onMessage($conn, $this->clientFrame('Hello World'));
    }

    public function testPlainComponentReceivesTheStringPayloadNotTheMessageObject()
    {
        $component = $this->createMock(DataComponentInterface::class);
        $component->expects($this->once())
            ->method('onMessage')
            ->with($this->isInstanceOf(WsConnection::class), 'plain payload');

        $server = new WsServer($component);
        $conn   = $this->openConnection($server);

        $server->onMessage($conn, $this->clientFrame('plain payload'));
    }

    public function testOnMessageIgnoresDataOnceTheConnectionIsClosing()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->never())->method('onMessage');

        $server = new WsServer($component);
        $conn   = $this->openConnection($server);
        $conn->WebSocket->closing = true;

        $server->onMessage($conn, $this->clientFrame('should be dropped'));
    }

    public function testOnCloseIsDelegatedForAnOpenConnection()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->once())
            ->method('onClose')
            ->with($this->isInstanceOf(WsConnection::class));

        $server = new WsServer($component);
        $conn   = $this->openConnection($server);

        $server->onClose($conn);
    }

    public function testOnCloseForAnUnknownConnectionIsIgnored()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->never())->method('onClose');

        $server = new WsServer($component);
        $server->onClose(new Connection());
    }

    public function testOnErrorIsDelegatedForAnOpenConnection()
    {
        $exception = new \Exception('boom');

        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->once())
            ->method('onError')
            ->with($this->isInstanceOf(WsConnection::class), $exception);

        $server = new WsServer($component);
        $conn   = $this->openConnection($server);

        $server->onError($conn, $exception);
    }

    public function testOnErrorForAnUnknownConnectionClosesItInstead()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $component->expects($this->never())->method('onError');

        $server = new WsServer($component);

        $conn = new Connection();
        $server->onError($conn, new \Exception('boom'));

        $this->assertTrue($conn->last['close']);
    }

    public function testAPingControlFrameIsAnsweredWithAPong()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $server    = new WsServer($component);
        $conn      = $this->openConnection($server);

        $server->onMessage($conn, $this->clientFrame('heartbeat', Frame::OP_PING));

        $this->assertSame(
            (new Frame('heartbeat', true, Frame::OP_PONG))->getContents(),
            $conn->last['send']
        );
    }

    public function testAClientCloseControlFrameClosesTheConnection()
    {
        $component = $this->createMock(MessageComponentInterface::class);
        $server    = new WsServer($component);
        $conn      = $this->openConnection($server);

        $server->onMessage($conn, $this->clientFrame(pack('n', 1000), Frame::OP_CLOSE));

        $this->assertTrue($conn->last['close']);
        $this->assertTrue($conn->WebSocket->closing);
    }

    public function testSubProtocolsFromTheComponentAreNegotiated()
    {
        $component = $this->createMock(\Ratchet\WebSocket\Stub\WsMessageComponentInterface::class);
        $component->method('getSubProtocols')->willReturn(['soap', 'wamp']);

        $server = new WsServer($component);
        $conn   = new Connection();
        $server->onOpen($conn, $this->newUpgradeRequest(['Sec-WebSocket-Protocol' => 'wamp']));

        $this->assertStringContainsString('101', $conn->last['send']);
        $this->assertStringContainsStringIgnoringCase('Sec-WebSocket-Protocol: wamp', $conn->last['send']);
    }

    public function testSetStrictSubProtocolCheckDoesNotError()
    {
        $server = new WsServer($this->createMock(MessageComponentInterface::class));

        // Simply exercising the setter (delegates to the negotiator).
        $server->setStrictSubProtocolCheck(false);
        $server->setStrictSubProtocolCheck(true);

        $this->assertTrue(true);
    }

    public function testEnableKeepAliveRegistersAPeriodicTimer()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())
            ->method('addPeriodicTimer')
            ->with(30, $this->isInstanceOf(\Closure::class));

        $server = new WsServer($this->createMock(MessageComponentInterface::class));
        $server->enableKeepAlive($loop, 30);
    }

    public function testEnableKeepAlivePingsOpenConnections()
    {
        $periodicCallback = null;

        $loop = $this->createMock(LoopInterface::class);
        $loop->method('addPeriodicTimer')
            ->willReturnCallback(function ($interval, $cb) use (&$periodicCallback) {
                $periodicCallback = $cb;
            });

        $server = new WsServer($this->createMock(MessageComponentInterface::class));
        $server->enableKeepAlive($loop, 30);

        $conn = $this->openConnection($server);
        $conn->last['send'] = ''; // discard the handshake response

        $this->assertIsCallable($periodicCallback);
        ($periodicCallback)();

        $this->assertNotSame('', $conn->last['send']);
        // First byte of a server ping frame: FIN (0x80) | OP_PING (0x9) = 0x89.
        $this->assertSame(0x89, ord($conn->last['send'][0]));
    }
}
