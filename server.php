<?php
require 'vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\Server as SocketServer;

class EstadoWS implements MessageComponentInterface {
    protected $clients;
    protected $db;
    protected $lastEstado = null;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->db = new mysqli("localhost", "root", "", "control_estado");
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);

        $r = $this->db->query("SELECT estado FROM estado WHERE id=1");
        $this->lastEstado = (int)$r->fetch_assoc()['estado'];

        $conn->send(json_encode(['estado' => $this->lastEstado]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);

        if (isset($data['estado'])) {
            $e = (int)$data['estado'];
            $this->db->query("UPDATE estado SET estado=$e WHERE id=1");
            $this->lastEstado = $e;

            foreach ($this->clients as $c) {
                $c->send(json_encode(['estado' => $e]));
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    public function watchDB() {
        $r = $this->db->query("SELECT estado FROM estado WHERE id=1");
        $e = (int)$r->fetch_assoc()['estado'];

        if ($e !== $this->lastEstado) {
            $this->lastEstado = $e;
            foreach ($this->clients as $c) {
                $c->send(json_encode(['estado' => $e]));
            }
        }
    }
}

$estado = new EstadoWS();
$loop = Loop::get();

$loop->addPeriodicTimer(1, fn() => $estado->watchDB());

$socket = new SocketServer('0.0.0.0:8080', $loop);
new IoServer(new HttpServer(new WsServer($estado)), $socket, $loop);

echo "WebSocket activo en ws://localhost:8080\n";
$loop->run();

