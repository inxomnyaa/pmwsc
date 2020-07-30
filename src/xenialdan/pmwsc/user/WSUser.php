<?php

namespace xenialdan\pmwsc\user;

use InvalidStateException;
use pocketmine\command\CommandSender;
use pocketmine\lang\TextContainer;
use pocketmine\permission\Permissible;
use pocketmine\permission\PermissibleBase;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionAttachment;
use pocketmine\permission\PermissionAttachmentInfo;
use pocketmine\permission\PermissionManager;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginException;
use pocketmine\Server;
use RuntimeException;

class WSUser extends WebSocketUser implements Permissible, CommandSender
{
    public $name;
    public $auth;
    public $id;
    public $authenticated = false;

    /** @var PermissibleBase */
    private $perm;
    /** @var bool */
    private $op = false;

    /** @var string */
    private $messages = '';

    public function __construct(int $id, $socket, bool $authenticated)
    {
        $this->perm = new PermissibleBase($this);
        parent::__construct($id, $socket);
        $this->id = $id;
        $this->authenticated = $authenticated;
    }

    public function __toString()
    {
        return __CLASS__ . ' ID: ' . $this->id . ' Authenticated ' . ($this->authenticated ? 'true' : 'false') . ' Name ' . $this->name . ' Socket ' . $this->socket;
    }

    /**
     * @param Permission|string $name
     *
     * @return bool
     */
    public function isPermissionSet($name): bool
    {
        return $this->perm->isPermissionSet($name);
    }

    /**
     * @param Permission|string $name
     *
     * @return bool
     *
     * @throws InvalidStateException if the player is closed
     */
    public function hasPermission($name): bool
    {
        if ($this->socket === null || !$this->authenticated) {
            throw new InvalidStateException('Trying to get permissions of closed socket, or not authenticated');
        }
        return $this->perm->hasPermission($name);
    }

    /**
     * @param Plugin $plugin
     * @param string $name
     * @param bool $value
     *
     * @return PermissionAttachment
     * @throws PluginException
     */
    public function addAttachment(Plugin $plugin, string $name = null, bool $value = null): PermissionAttachment
    {
        return $this->perm->addAttachment($plugin, $name, $value);
    }

    /**
     * @param PermissionAttachment $attachment
     */
    public function removeAttachment(PermissionAttachment $attachment)
    {
        $this->perm->removeAttachment($attachment);
    }

    public function recalculatePermissions()
    {
        $permManager = PermissionManager::getInstance();
        $permManager->unsubscribeFromPermission(Server::BROADCAST_CHANNEL_USERS, $this);
        $permManager->unsubscribeFromPermission(Server::BROADCAST_CHANNEL_ADMINISTRATIVE, $this);
        if ($this->perm === null) {
            return;
        }

        $this->perm->clearPermissions();
        if (!$this->authenticated) return;
        $permManager->subscribeToDefaultPerms($this->isOp(), $this ?? $this->perm);

        $permManager->subscribeToPermission(Server::BROADCAST_CHANNEL_USERS, $this);
        if ($this->isOp()) {
            var_dump('hasAdmin');
            $permManager->subscribeToPermission(Server::BROADCAST_CHANNEL_ADMINISTRATIVE, $this);
        }
    }

    /**
     * @return PermissionAttachmentInfo[]
     */
    public function getEffectivePermissions(): array
    {
        return $this->perm->getEffectivePermissions();
    }

    /**
     * Checks if the current object has operator permissions
     *
     * @return bool
     */
    public function isOp(): bool
    {
        return $this->op;
    }

    /**
     * Sets the operator permission for the current object
     *
     * @param bool $value
     */
    public function setOp(bool $value)
    {
        $this->op = $value;
    }

    /**
     * @param TextContainer|string $message
     * @throws RuntimeException
     */
    public function sendMessage($message)
    {
        if ($message instanceof TextContainer) {
            $message = Server::getInstance()->getLanguage()->translate($message);
        } else {
            $message = Server::getInstance()->getLanguage()->translateString($message);
        }

        $this->messages .= trim($message, "\r\n") . "\n";
    }

    public function getMessage()
    {
        return $this->messages;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the line height of the command-sender's screen. Used for determining sizes for command output pagination
     * such as in the /help command.
     *
     * @return int
     */
    public function getScreenLineHeight(): int
    {
        return PHP_INT_MAX;
    }

    /**
     * Sets the line height used for command output pagination for this command sender. `null` will reset it to default.
     *
     * @param int|null $height
     */
    public function setScreenLineHeight(int $height = null)
    {
        // nope
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function getServer()
    {
        return Server::getInstance();
    }
}