<?php
namespace xenialdan\pmwsc\user;

class WSUser extends WebSocketUser {
    public $name;
    public $auth;
    public $id;
  public $authenticated = false;

  function __construct(int $id, $socket, bool $authenticated) {
    parent::__construct($id, $socket);
    $this->id = $id;
    $this->authenticated = $authenticated;
  }

  public function __toString()
  {
      return __CLASS__." ID: ".$this->id . " Authenticated " . ($this->authenticated?"true":"false") . " Name " . $this->name . " Socket " . $this->socket;
  }
}