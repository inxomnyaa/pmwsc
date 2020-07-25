<?php

declare(strict_types=1);

namespace xenialdan\pmwsc;

use Exception;
use InvalidArgumentException;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\utils\Config;
use raklib\utils\InternetAddress;
use xenialdan\pmwsc\command\WebsocketCommand;
use xenialdan\pmwsc\listener\EventListener;
use xenialdan\pmwsc\ws\tcp\WS;

class Loader extends PluginBase
{
    /** @var Loader */
    private static $instance = null;
    /** @var InternetAddress */
    private static $ia;
    /** @var WS */
    public $websocketServer;
    /** @var Config */
    public static $passwords;

    /**
     * Returns an instance of the plugin
     * @return Loader
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    public function onLoad()
    {
        self::$instance = $this;
    }

    /**
     * @throws PluginException
     * @throws InvalidArgumentException
     */
    public function onEnable()
    {
        $this->reloadConfig();
        //save files
        foreach (array_keys($this->getResources()) as $path) {
            $this->saveResource($path);
            //$this->saveResource($path, true);//TODO remove
        }
        //start website websocket listener thread
        $serverRoot = $this->getDataFolder() . "wwwroot";
        $ws = \Frago9876543210\WebServer\API::startWebServer($this, \Frago9876543210\WebServer\API::getPathHandler($serverRoot));
        if ($ws === null) {
            throw new PluginException('Could not start WebServer, disabling!');
        }
        $ws->getClassLoader()->getParent()->addPath(realpath($serverRoot), true);
        //generate login files
        self::$passwords = new Config($this->getDataFolder() . "passwords.yml");
        $port = (int)($this->getConfig()->get("port", 9000));
        self::$ia = new InternetAddress($this->getServer()->getIp(), $port, 4);
        $this->getServer()->getCommandMap()->register("websocket", new WebsocketCommand("websocket", $this));
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        //start the messaging websocket listener thread
        $this->startWebsocketServer();
    }

    public function onDisable()
    {
        if ($this->websocketServer instanceof WS) {
            $this->websocketServer->stop();
        }
    }

    private function startWebsocketServer()
    {
        try {
            $this->websocketServer = new WS(
                $this->getServer(),
                self::$ia
            );
        } catch (Exception $e) {
            $this->getLogger()->logException($e);
            throw new PluginException('Could not start Websocket, disabling! Reason: ' . $e->getMessage());
        }
    }

    public static function getAuthCode(string $playername, bool $createNew = true): string
    {
        $playername = strtolower($playername);
        if ($createNew || !self::$passwords->exists($playername, true)) {
            $characters = "ABCDEFGHKLMNPQRSTVWXYZ123456789";
            $auth = substr(str_shuffle($characters), 5, 5);
            self::$passwords->set($playername, strtolower($auth));
            self::$passwords->save();
        } else {
            $auth = self::$passwords->get($playername);
        }
        return $auth;
    }

    /* TODO list
    Authentication
    Message forwarding
    Better styling
    Logo
    Website
    Config
    */
}