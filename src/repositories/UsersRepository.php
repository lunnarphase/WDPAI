<?php

require_once 'Repository.php';

class UsersRepository extends Repository {

    public function getUsers(): ?array 
    {
        $query = $this->database->connect()->prepare(
            "SELECT * FROM users"
        );
        $query->execute();

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserByEmail(string $email) {
        $query = $this->database->connect()->prepare(
            "SELECT * FROM users WHERE email = :email"
        );
        $query->bindParam(':email', $email, PDO::PARAM_STR);
        $query->execute();

        $user = $query->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function createUser(string $email, string $hashedPassword, string $username) {
        $query = $this->database->connect()->prepare(
            "INSERT INTO users (username, email, password) VALUES (?, ?, ?)"
        );
        
        $query->execute([
            $username,
            $email,
            $hashedPassword
        ]);
    }
}