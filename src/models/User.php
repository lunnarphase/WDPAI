<?php

class User {
    private ?int $id;
    private string $email;
    private string $password;
    private string $username;
    private string $role; 

    public function __construct(string $email, string $password, string $username, string $role = 'patient', ?int $id = null) {
        $this->email = $email;
        $this->password = $password;
        $this->username = $username;
        $this->role = $role;
        $this->id = $id;
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function getEmail(): string {
        return $this->email;
    }

    public function getPassword(): string {
        return $this->password;
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function getRole(): string {
        return $this->role;
    }
}