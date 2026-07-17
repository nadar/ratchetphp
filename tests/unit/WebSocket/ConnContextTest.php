<?php

namespace Ratchet\WebSocket;

use Ratchet\Mock\Connection;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use PHPUnit\Framework\TestCase;

/**
 * @covers Ratchet\WebSocket\ConnContext
 */
class ConnContextTest extends TestCase
{
    public function testItExposesTheConnectionAndBufferItWasBuiltWith()
    {
        $conn   = new WsConnection(new Connection());
        $buffer = new MessageBuffer(
            new CloseFrameChecker(),
            function () {
            },
            function () {
            }
        );

        $context = new ConnContext($conn, $buffer);

        $this->assertSame($conn, $context->connection);
        $this->assertSame($buffer, $context->buffer);
    }
}
