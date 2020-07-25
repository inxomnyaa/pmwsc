<?php


namespace xenialdan\pmwsc\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\plugin\Plugin;
use xenialdan\pmwsc\Loader;

class EventListener implements Listener
{
    public $owner;

    public function __construct(Plugin $plugin)
    {
        $this->owner = $plugin;
    }

    public function onMessage(PlayerChatEvent $event){
        $fullMessage = $this->owner->getServer()->getLanguage()->translateString($event->getFormat(), [$event->getPlayer()->getDisplayName(), $event->getMessage()]);
        Loader::getInstance()->websocketServer->getInstance()->broadcast('[Server] ' . $fullMessage);
    }

    public function onLogin(PlayerLoginEvent $event){
        Loader::getAuthCode($event->getPlayer()->getName(), false);
    }

}