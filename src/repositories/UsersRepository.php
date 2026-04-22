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

    public function createUser(string $email, string $password, string $username, string $name, string $surname, string $pesel): void 
    {
        $db = $this->database->connect();
        
        try {
            $db->beginTransaction(); // START TRANSAKCJI

            // 1. Wstawiamy do tabeli USERS
            $stmt = $db->prepare('
                INSERT INTO users (email, password, username, id_role)
                VALUES (?, ?, ?, 3) RETURNING id
            ');
            $stmt->execute([$email, $password, $username]);
            
            // Pobieramy wygenerowane ID użytkownika
            $userId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];

            // 2. Wstawiamy do tabeli PATIENTS (korzystając z pobranego ID)
            $stmt = $db->prepare('
                INSERT INTO patients (id_user, pesel)
                VALUES (?, ?)
            ');
            $stmt->execute([$userId, $pesel]);

            $db->commit(); // ZATWIERDZENIE - wszystko OK
        } catch (Exception $e) {
            $db->rollBack(); // WYCOFANIE - jeśli coś poszło nie tak
            throw $e;
        }
    }
}