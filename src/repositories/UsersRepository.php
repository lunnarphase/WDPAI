<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/User.php';

class UsersRepository extends Repository {

    public function getUserByEmail(string $email): ?User {
        // Używamy JOIN, aby pobrać nazwę roli z tabeli 'roles'
        $query = $this->database->connect()->prepare(
            "SELECT u.id, u.email, u.password, u.username, r.name as role 
             FROM users u 
             JOIN roles r ON u.id_role = r.id 
             WHERE u.email = :email"
        );
        $query->bindParam(':email', $email, PDO::PARAM_STR);
        $query->execute();

        $user = $query->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        return new User(
            $user['email'],
            $user['password'],
            $user['username'],
            $user['role'], 
            $user['id']
        );
    }

    public function createUser(string $email, string $hashedPassword, string $username): void {
        // Zamiast tekstu 'patient', wstawiamy id_role = 3 (bo 3 to 'patient' w naszej tabeli roles)
        $query = $this->database->connect()->prepare(
            "INSERT INTO users (email, password, username, id_role) VALUES (?, ?, ?, 3)"
        );
        
        $query->execute([$email, $hashedPassword, $username]);
    }
}