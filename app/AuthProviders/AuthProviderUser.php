<?php

namespace App\AuthProviders;

class AuthProviderUser
{


  public $sub;
  public $name;
  public $email;
  public $avatar;
  public $verified;
  // The ID token of the provider, when it sends one: it lets the logout close the provider session
  public ?string $idToken = null;

  public function __construct($user)
  {

    //we need all the data to be set
    if (!isset($user['sub']) || !isset($user['name']) || !isset($user['email']) || !isset($user['verified'])) {
      throw new \Exception('Missing required data');
    }

    $this->sub = $user['sub'];
    $this->name = $user['name'];
    $this->email = $user['email'];
    $this->avatar = $user['avatar'];
    $this->verified = $user['verified'];
  }
}
