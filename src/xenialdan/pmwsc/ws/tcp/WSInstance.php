<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace xenialdan\pmwsc\ws\tcp;

use pocketmine\snooze\SleeperNotifier;
use pocketmine\Thread;
use pocketmine\utils\TextFormat;
use xenialdan\pmwsc\user\WSUser;

class WSInstance extends Thread
{
    const MESSAGE_TYPE_CONTINUOUS = 0;
    const MESSAGE_TYPE_TEXT = 1;
    const MESSAGE_TYPE_BINARY = 2;
    const MESSAGE_TYPE_CLOSE = 8;
    const MESSAGE_TYPE_PING = 9;
    const MESSAGE_TYPE_PONG = 10;

    /** @var string */
    public $cmd;
    /** @var string */
    public $response;
    /** @var WSUser */
    public $user;
    protected $headerOriginRequired = false;
    protected $headerSecWebSocketProtocolRequired = false;
    protected $headerSecWebSocketExtensionsRequired = false;
    /** @var bool */
    private $stop;
    /** @var resource */
    private $socket;
    /** @var int */
    private $maxClients;
    /** @var \ThreadedLogger */
    private $logger;
    /** @var resource */
    private $ipcSocket;
    /** @var SleeperNotifier|null */
    private $notifier;
    /** @var WSUser[] */
    private $users;
    /** @var resource[] */
    private $clients;

    /**
     * @param resource $socket
     * @param int $maxClients
     * @param \ThreadedLogger $logger
     * @param resource $ipcSocket
     * @param null|SleeperNotifier $notifier
     */
    public function __construct($socket, int $maxClients = 50, \ThreadedLogger $logger, $ipcSocket, ?SleeperNotifier $notifier)
    {
        $this->stop = \false;
        $this->cmd = "";
        $this->response = "";
        $this->socket = $socket;
        $this->maxClients = $maxClients;
        $this->logger = $logger;
        $this->ipcSocket = $ipcSocket;
        $this->notifier = $notifier;
        $this->clients = [];
        $this->users = [];

        $this->start(PTHREADS_INHERIT_NONE);
    }

    /**
     * Executes a send() on every user
     * @param string $message
     */
    public function broadcast(string $message)
    {
        foreach ($this->users as $user) {
            $user->socket = $this->getSocketByUser($user);
            $this->send($user, $message);
        }
    }

    /**
     * Escapes a message for use in a browser and sends it to the user
     * @param WSUser $user
     * @param string $message
     */
    protected function send(WSUser $user, string $message)
    {
        if (empty(trim($message))) return;
        if ($user->handshake) {
            $this->logger->notice((string)$user);
            $message = htmlspecialchars($message);
            $message = TextFormat::toHTML($message);
            $message = str_replace("\n", "<br>", $message);
            $message = $this->frame($message, $user);
            $this->writePacket($user->socket, $message);
        } else {
            // User has not yet performed their handshake.  Store for sending later.
            #$this->heldMessages[] = ['user' => $user, 'message' => $message];
        }
    }

    /**
     * TODO figure out what this function does
     * @param string $message
     * @param WSUser $user
     * @param int $messageType
     * @param bool $messageContinues
     * @return string
     */
    protected function frame(string $message, WSUser $user, int $messageType = self::MESSAGE_TYPE_TEXT, bool $messageContinues = false)
    {
        $b1 = $messageType;
        if ($user->sendingContinuous && ($b1 === self::MESSAGE_TYPE_TEXT || $b1 === self::MESSAGE_TYPE_BINARY)) $b1 = self::MESSAGE_TYPE_CONTINUOUS;
        if ($messageContinues) {
            $user->sendingContinuous = true;
        } else {
            $b1 += 128;
            $user->sendingContinuous = false;
        }

        $length = strlen($message);
        $lengthField = "";
        if ($length < 126) {
            $b2 = $length;
        } else if ($length < 65536) {
            $b2 = 126;
            $hexLength = dechex($length);
            //$this->stdout("Hex Length: $hexLength");
            if (strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;

            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 2) {
                $lengthField = chr(0) . $lengthField;
            }
        } else {
            $b2 = 127;
            $hexLength = dechex($length);
            if (strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;

            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 8) {
                $lengthField = chr(0) . $lengthField;
            }
        }
        return chr($b1) . chr($b2) . $lengthField . $message;
    }

    private function writePacket($client, string $message)
    {
        return \socket_write($client, $message);
    }

    public function close()
    {
        foreach ($this->clients as $client) {
            $this->disconnectClient($client, "Server stopping");
        }
        unset($this->clients);
        gc_collect_cycles();
        $this->stop = \true;
    }

    /**
     * Executed when the thread starts.
     * This function handles incoming connections, connects users,
     * authenticates them or disconnects them on authentication errors
     */
    public function run()
    {
        $this->registerClassLoader();
        /** @var float[] $timeouts */
        $timeouts = [];

        /** @var int $nextClientId */
        $nextClientId = 0;

        while (!$this->stop) {
            $r = (array)$this->clients;
            $r["main"] = $this->socket; //this is ugly, but we need to be able to mass-select()
            #$r["ipc"] = $this->ipcSocket;
            $w = \null;
            $e = \null;

            $disconnect = [];

            if (\socket_select($r, $w, $e, 5, 0) > 0) {
                foreach ($r as $id => $sock) {
                    if ($sock === $this->socket) {
                        if (($client = \socket_accept($this->socket)) !== \false) {
                            if (\count($this->clients) >= $this->maxClients) {
                                $this->disconnectClient($client, "Too many clients are already connected.");
                            } else {
                                \socket_set_block($client);//TODO check if non block
                                \socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);

                                $id = $nextClientId++;
                                $clients[$id] = $client;
                                $this->clients[$id] = $client;
                                $this->connectClient($client, $id, false);
                                $timeouts[$id] = \microtime(\true) + 5;
                                \socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 5, "usec" => 0]);//TODO check if this causes trouble
                            }
                        }//TODO else client could not connect-> error checks
                    } else if ($sock === $this->ipcSocket) {//todo check if needed
                        //read dummy data
                        \socket_read($sock, 65535);
                    } else {
                        $this->readPacket($sock, $message);
                    }
                }
            }

            foreach ($timeouts as $id => $timeout) {
                $user = $this->users[$id];
                if ($user instanceof WSUser && !$user->authenticated && $timeout < \microtime(\true)) { //Timeout
                    $this->logger->info("User ID $id will be disconnected as timed out - not authenticated after 5 seconds");
                    $disconnect[$id] = $this->clients[$id];
                    unset($timeouts[$id]);
                }
            }

            foreach ($disconnect as $id => $client) {
                $this->disconnectClient($client);
                unset($this->clients[$id], $timeouts[$id], $disconnect[$id]);
            }
        }

        foreach ($this->clients as $client) {
            $this->disconnectClient($client, "Server stopping");
        }

        $this->logger->info("Stopping " . $this->getThreadName());
    }

    /**
     * Connects a client. Binds the socket to a new user object
     * @param resource $socket
     * @param int $id
     * @param bool $authenticated
     */
    protected function connectClient($socket, int $id, bool $authenticated)
    {
        $user = new WSUser($id, $socket, $authenticated);
        $this->users[$id] = $user;
        $this->connectUser($user);
    }

    /**
     * Returns a user by socket resource and handles disconnecting invalid users / socket connections
     * @param resource $socket
     * @return null|WSUser
     */
    protected function getUserBySocket($socket)
    {
        foreach ($this->clients as $id => $client) {
            if ($client === $socket) {
                $user = $this->users[$id];
                if (!$user instanceof WSUser || $user->id !== $id) {
                    $this->logger->error("User " . $user . " is invalid");
                    return null;
                }
                $user->socket = $this->getSocketByUser($user);
                return $user;
            }
        }
        $this->logger->error("Did not find user for socket $socket, disconnecting");
        $this->disconnectClient($socket, "Internal server error");
        return null;
    }

    /**
     * @param WSUser $user
     * @return null|resource
     */
    protected function getSocketByUser(WSUser $user)
    {
        return $this->clients[$user->id] ?? null;
    }

    /**
     * @param $client
     * @param null|string $message
     * @return bool
     */
    private function readPacket($client, ?string &$message)
    {
        $numBytes = @socket_recv($client, $message, 2048, 0);
        if ($this->stop) {
            return \false;
        } else if ($numBytes === false) {
            $sockErrNo = \socket_last_error($client);
            switch ($sockErrNo) {
                case SOCKET_ENETRESET: //Network dropped connection because of reset
                case SOCKET_ECONNABORTED: //Software caused connection abort
                case SOCKET_ECONNRESET: //Connection reset by peer
                case SOCKET_ESHUTDOWN: //Cannot send after transport endpoint shutdown -- probably more of an error on our part, if we're trying to write after the socket is closed.  Probably not a critical error, though.
                case SOCKET_ETIMEDOUT: //Connection timed out
                case SOCKET_ECONNREFUSED: //Connection refused -- We shouldn't see this one, since we're listening... Still not a critical error.
                case SOCKET_EHOSTDOWN: //Host is down -- Again, we shouldn't see this, and again, not critical because it's just one connection and we still want to listen to/for others.
                case SOCKET_EHOSTUNREACH: //No route to host
                case SOCKET_EREMOTEIO: //Remote I/O error -- Their hard drive just blew up.
                case 125: //ECANCELED - Operation canceled
                    $emsg = "Unusual disconnect $sockErrNo on socket $client (" . socket_strerror($sockErrNo) . " - " . $this->getSocketEString($sockErrNo) . ")";
                    $this->logger->error($emsg);
                    $this->disconnectClient($client, $emsg); // disconnect before clearing error, in case someone with their own implementation wants to check for error conditions on the socket.
                    break;
                default:
                    $this->logger->error('Socket error: ' . socket_strerror($sockErrNo));
            }
            //socket_clear_error($client);//TODO clear error?
        } else if ($numBytes == 0) {
            $this->disconnectClient($client, "TCP connection lost");
            return false;
        } else {
            //socket_set_option($socket,SOL_SOCKET, SO_RCVTIMEO, array("sec"=>5, "usec"=>0));//TODO check if this causes trouble
            $user = $this->getUserBySocket($client);
            if ($user instanceof WSUser && !$user->authenticated) {
                $tmp = str_replace("\r", '', $message);
                if (strpos($tmp, "\n\n") === false) {
                    return false; // If the client has not finished sending the header, then wait before sending our upgrade response.
                }
                $this->doHandshake($user, $message);
            } else if ($user instanceof WSUser) {
                //decode message
                $headers = $this->extractHeaders($message);
                if (($message = $this->deframe($message, $user, $headers)) !== false) {
                    $this->process($user, $message);
                }
            }
        }
        return \true;
    }

    private function getSocketEString(int $sockErrNo)
    {
        switch ($sockErrNo) {
            case SOCKET_ENETRESET:
                return "Network dropped connection because of reset";
                break;
            case SOCKET_ECONNABORTED:
                return "Software caused connection abort";
                break;
            case SOCKET_ECONNRESET:
                return "Connection reset by peer";
                break;
            case SOCKET_ESHUTDOWN:
                return "Cannot send after transport endpoint shutdown -- probably more of an error on our part, if we're trying to write after the socket is closed.  Probably not a critical error, though.";
                break;
            case SOCKET_ETIMEDOUT:
                return "Connection timed out";
                break;
            case SOCKET_ECONNREFUSED:
                return "Connection refused -- We shouldn't see this one, since we're listening... Still not a critical error.";
                break;
            case SOCKET_EHOSTDOWN:
                return "Host is down -- Again, we shouldn't see this, and again, not critical because it's just one connection and we still want to listen to/for others.";
                break;
            case SOCKET_EHOSTUNREACH:
                return "No route to host";
                break;
            case SOCKET_EREMOTEIO:
                return "Remote I/O error -- Their hard drive just blew up.";
                break;
            case 125://ECANCELED
                return "Operation canceled";
                break;
            default:
                return "Unknown Socket error No.$sockErrNo";
        }
    }

    private function disconnectClient($client, string $reason = ""): void
    {
        $reason2 = $reason === "" ?: " (Reason: $reason)";
        if (!is_resource($client)) {
            $this->logger->error("Client is " . gettype($client));
            return;
        }
        $this->logger->info("Disconnect socket " . $client . $reason2);
        $user = $this->getUserBySocket($client);
        if ($user instanceof WSUser) {
            $this->logger->debug("Disconnecting user " . $user . $reason2);
            if ($reason !== "") $this->send($user, TextFormat::RED . "Disconnecting" . $reason2);
            unset($this->users[$user->id]);
        }
        @\socket_set_option($client, SOL_SOCKET, SO_LINGER, ["l_onoff" => 1, "l_linger" => 1]);
        @\socket_shutdown($client, 2);
        @\socket_set_block($client);
        @\socket_read($client, 1);
        @\socket_close($client);
        foreach ($this->clients as $id => $socket) {
            if ($socket === $client) {
                unset($this->clients[$id]);
                break;
            }
        }
    }

    /**
     * Does the handshake progress between the user and the server
     * @param WSUser $user
     * @param string $buffer
     */
    protected function doHandshake(WSUser $user, string $buffer)
    {
        $magicGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";//TODO check if this should be random
        $headers = [];
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            if (strpos($line, ":") !== false) {
                $header = explode(":", $line, 2);
                $headers[strtolower(trim($header[0]))] = trim($header[1]);
            } else if (stripos($line, "get ") !== false) {
                preg_match("/GET (.*) HTTP/i", $buffer, $reqResource);
                $headers['get'] = trim($reqResource[1]);
            }
        }
        if (isset($headers['get'])) {
            $user->requestedResource = $headers['get'];
        } else {
            // todo: fail the connection // but why?
            $handshakeResponse = "HTTP/1.1 405 Method Not Allowed\r\n\r\n";
        }
        if (!isset($headers['host']) || !$this->checkHost($headers['host'])) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['upgrade']) || strtolower($headers['upgrade']) != 'websocket') {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === false) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['sec-websocket-key'])) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['sec-websocket-version']) || strtolower($headers['sec-websocket-version']) != 13) {
            $handshakeResponse = "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocketVersion: 13";
        }
        if (($this->headerOriginRequired && !isset($headers['origin'])) || ($this->headerOriginRequired && !$this->checkOrigin($headers['origin']))) {
            $handshakeResponse = "HTTP/1.1 403 Forbidden";
        }
        if (($this->headerSecWebSocketProtocolRequired && !isset($headers['sec-websocket-protocol'])) || ($this->headerSecWebSocketProtocolRequired && !$this->checkWebsocProtocol($headers['sec-websocket-protocol']))) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (($this->headerSecWebSocketExtensionsRequired && !isset($headers['sec-websocket-extensions'])) || ($this->headerSecWebSocketExtensionsRequired && !$this->checkWebsocExtensions($headers['sec-websocket-extensions']))) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }

        // Done verifying the _required_ headers and optionally required headers.

        if (isset($handshakeResponse)) {
            $this->writePacket($user->socket, $handshakeResponse);
            #$this->disconnectClient($user->socket);
            return;
        }

        $user->headers = $headers;
        $user->handshake = $buffer;

        $webSocketKeyHash = sha1($headers['sec-websocket-key'] . $magicGUID);

        $rawToken = "";
        for ($i = 0; $i < 20; $i++) {
            $rawToken .= chr(hexdec(substr($webSocketKeyHash, $i * 2, 2)));
        }
        $handshakeToken = base64_encode($rawToken) . "\r\n";

        $subProtocol = (isset($headers['sec-websocket-protocol'])) ? $this->processProtocol($headers['sec-websocket-protocol']) : "";
        $extensions = (isset($headers['sec-websocket-extensions'])) ? $this->processExtensions($headers['sec-websocket-extensions']) : "";

        $handshakeResponse = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $handshakeToken$subProtocol$extensions\r\n";
        $this->logger->debug("Sending upgrade");
        $this->writePacket($user->socket, $handshakeResponse);
        $this->connected($user);
    }

    /**
     * Override and return false if the host is not one that you would expect.
     * Ex: You only want to accept hosts from the my-domain.com domain,
     * but you receive a host from malicious-site.com instead.
     * @param string $hostName
     * @return bool
     */
    protected function checkHost(string $hostName)
    {
        return true;
    }

    /**
     * Override and return false if the origin is not one that you would expect.
     * @param string $origin
     * @return bool
     */
    protected function checkOrigin(string $origin)
    {
        return true;
    }

    /**
     * Override and return false if a protocol is not found that you would expect.
     * @param string $protocol
     * @return bool
     */
    protected function checkWebsocProtocol(string $protocol)
    {
        return true;
    }

    /**
     * Override and return false if an extension is not found that you would expect.
     * @param string $extensions
     * @return bool
     */
    protected function checkWebsocExtensions(string $extensions)
    {
        return true;
    }

    /**
     * return either "Sec-WebSocket-Protocol: SelectedProtocolFromClientList\r\n" or return an empty string.
     * The carriage return/newline combo must appear at the end of a non-empty string, and must not
     * appear at the beginning of the string nor in an otherwise empty string, or it will be considered part of
     * the response body, which will trigger an error in the client as it will not be formatted correctly.
     * @param string $protocol
     * @return string
     */
    protected function processProtocol(string $protocol)
    {
        return "";
    }

    /**
     * return either "Sec-WebSocket-Extensions: SelectedExtensions\r\n" or return an empty string.
     * @param string $extensions
     * @return string
     */
    protected function processExtensions(string $extensions)
    {
        return "";
    }

    /**
     * Called immediately when the data is received.
     * @param WSUser $user
     */
    protected function connected(WSUser $user)
    {
        $user->authenticated = true;
        $this->users[$user->id] = $user;
        $this->logger->info("Logged in and authenticated user " . $this->users[$user->id]);
        $user->recalculatePermissions();
    }

    /**
     * Called after the handshake response is sent to the client.
     * @param string $message
     * @return array
     */
    protected function extractHeaders(string $message)
    {
        $header = ['fin' => $message[0] & chr(128),
            'rsv1' => $message[0] & chr(64),
            'rsv2' => $message[0] & chr(32),
            'rsv3' => $message[0] & chr(16),
            'opcode' => ord($message[0]) & 15,
            'hasmask' => $message[1] & chr(128),
            'length' => 0,
            'mask' => ""];
        $header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);

        if ($header['length'] == 126) {
            if ($header['hasmask']) {
                $header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
            }
            $header['length'] = ord($message[2]) * 256
                + ord($message[3]);
        } else if ($header['length'] == 127) {
            if ($header['hasmask']) {
                $header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
            }
            $header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256
                + ord($message[3]) * 65536 * 65536 * 65536
                + ord($message[4]) * 65536 * 65536 * 256
                + ord($message[5]) * 65536 * 65536
                + ord($message[6]) * 65536 * 256
                + ord($message[7]) * 65536
                + ord($message[8]) * 256
                + ord($message[9]);
        } else if ($header['hasmask']) {
            $header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
        }
        return $header;
    }

    /**
     * @param array $headers
     * @return int
     */
    protected function calcoffset(array $headers)
    {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } else if ($headers['length'] > 125) {
            $offset += 2;
        }
        return $offset;
    }

    /**
     * Decodes encoded messages into headers and the message
     * @param string $message
     * @param WSUser $user
     * @param array $headers
     * @return bool|int|string
     */
    protected function deframe(string $message, WSUser &$user, array $headers)
    {
        //echo $this->strtohex($message);
        $pongReply = false;
        switch ($headers['opcode']) {
            case 0:
            case 1:
            case 2:
                break;
            case self::MESSAGE_TYPE_CLOSE:
                $this->disconnectClient($user->socket, "User disconnected");
                return "";
            case self::MESSAGE_TYPE_PING:
                var_dump("!!!GOT PING!!!");
                $pongReply = true;
                break;
            case self::MESSAGE_TYPE_PONG:
                break;
            default:
                $this->disconnectClient($user->socket, "Unhandled opcode happened: " . $headers["opcode"]);
                return false;
                break;
        }

        if ($this->checkRSVBits($headers, $user)) {
            var_dump("!!!RSV BITS FAILED!!!");
            return false;
        }

        $payload = $user->partialMessage . $this->extractPayload($message, $headers);

        if ($pongReply) {
            var_dump("!!!WILL PONGREPLY!!!");
            $reply = $this->frame($payload, $user, self::MESSAGE_TYPE_PONG);
            $this->logger->debug("$user PONG");
            $this->writePacket($user->socket, $reply);
            var_dump("!!!DID SEND PONG!!!");
            return false;
        }
        if ($headers['length'] > strlen($this->applyMask($headers, $payload))) {
            $user->handlingPartialPacket = true;
            $user->partialBuffer = $message;
            return false;
        }

        $payload = $this->applyMask($headers, $payload);

        if ($headers['fin']) {
            $user->partialMessage = "";//TODO figure out what this is and if it can be removed
            return $payload;
        }
        $user->partialMessage = $payload;//ah ok this is added to the $payload on every new packet
        return false;
    }

    /**
     * Override this method if you are using an extension where the RSV bits are used.
     * @param array $headers
     * @param WSUser $user
     * @return bool
     */
    protected function checkRSVBits(array $headers, WSUser $user)
    {
        if (ord($headers['rsv1']) + ord($headers['rsv2']) + ord($headers['rsv3']) > 0) {
            $this->disconnectClient($user->socket, "Invalid RSV bits");
            return true;
        }
        return false;
    }

    /**
     * @param string $message
     * @param array $headers
     * @return bool|string
     */
    protected function extractPayload(string $message, array $headers)
    {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } else if ($headers['length'] > 125) {
            $offset += 2;
        }
        return substr($message, $offset);
    }

    /**
     * Applies the mask to the payload if any is used
     * @param $headers
     * @param $payload
     * @return int
     */
    protected function applyMask($headers, $payload)
    {
        $effectiveMask = "";
        if ($headers['hasmask']) {
            $mask = $headers['mask'];
        } else {
            return $payload;
        }

        while (strlen($effectiveMask) < strlen($payload)) {
            $effectiveMask .= $mask;
        }
        while (strlen($effectiveMask) > strlen($payload)) {
            $effectiveMask = substr($effectiveMask, 0, -1);
        }
        return $effectiveMask ^ $payload;
    }

    /**
     * The name of the thread used in the Logger
     * @return string
     */
    public function getThreadName(): string
    {
        return "WS Server";
    }

    /**
     * Returns a hex string version of the string
     * @param string $str
     * @return string
     */
    protected function strtohex(string $str)
    {
        $strout = "";
        for ($i = 0; $i < strlen($str); $i++) {
            $strout .= (ord($str[$i]) < 16) ? "0" . dechex(ord($str[$i])) : dechex(ord($str[$i]));
            $strout .= " ";
            if ($i % 32 == 7) {
                $strout .= ": ";
            }
            if ($i % 32 == 15) {
                $strout .= ": ";
            }
            if ($i % 32 == 23) {
                $strout .= ": ";
            }
            if ($i % 32 == 31) {
                $strout .= "\n";
            }
        }
        return $strout . "\n";
    }

    /**
     * Prints out the headers as hex dump
     * @param array $headers
     */
    protected function printHeaders(array $headers)
    {
        echo "Array\n(\n";
        foreach ($headers as $key => $value) {
            if ($key == 'length' || $key == 'opcode') {
                echo "\t[$key] => $value\n\n";
            } else {
                echo "\t[$key] => " . $this->strtohex($value) . "\n";

            }

        }
        echo ")\n";
    }

    /**
     * @param WSUser $user
     * @param string $message
     */
    protected function process(WSUser $user, string $message)
    {
        $this->logger->debug("Read packet and got: $message");
        if (!$user->authenticated) {
            $this->logger->debug("User is not authenticated, disconnecting");
            $this->disconnectClient($user->socket, "User is not authenticated");
            return;
        }
        if ($user->name === null || $user->name === "" || $user->auth === null || $user->auth === "") {
            if (preg_match("/U:([A-Za-z0-9_ ])A:([A-HK-NP-TV-Za-hk-np-tv-z1-9])/", $message, $matches) === 1) {
                var_dump($matches);
            }
            if (preg_match("/U:(.*)A:(.*)/", $message, $matches) === 1) {
                $user->name = $matches[1];
                $user->auth = $matches[2];
                $this->users[$user->id] = $user;
                $this->user = $user;
                $this->synchronized(
                    function () {
                        $this->notifier->wakeupSleeper();
                        $this->wait();
                    });
                $user = $this->user;
                $this->user = null;
                if (!$user->authenticated) {
                    $this->logger->debug("User has given an invalid auth code");
                    $user->socket = $this->getSocketByUser($user);
                    $this->disconnectClient($user->socket, "Invalid auth code");
                }
                return;
            }
        }
        #$this->send($user, "Echo @" . $user->name . ": " . $message);//echo to the user
        if ($message !== "") {
            if ($message[0] === "/") {
                $this->cmd = $message;
                $this->logger->debug("Wake up sleeper and call command " . $this->cmd);
            } else {
                $this->cmd = $message;
                $this->logger->debug("Wake up sleeper and send message " . $this->cmd);
            }
            $this->user = $user;
            $this->synchronized(
                function () {
                    $this->notifier->wakeupSleeper();
                    $this->wait();
                });
            $this->user = null;
            if ($message[0] === "/") {
                $this->logger->debug("Command response " . $this->response);
                $this->send($user, str_replace("\r", "\t", \trim($this->response)));
            } else {
                //Yes, this must be after the sleeper. Server might want it that way lol
                $this->logger->debug("Broadcast message " . $this->response);
                $this->broadcast($this->response);
            }
            $this->response = "";//todo save it per user
            $this->cmd = "";
        }
    }

    /**
     * @param WSUser $user
     */
    protected function closed(WSUser $user)
    {
    }

    /**
     * Override to handle a connecting user, after the instance of the User is created,
     * but before the handshake has completed.
     * @param WSUser $user
     */
    protected function connectUser(WSUser $user)
    {
        $this->logger->debug("Connecting user " . $user);
    }
}
