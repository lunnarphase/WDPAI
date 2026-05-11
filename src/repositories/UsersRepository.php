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

    public function getUsersCount(): int {
        $db = $this->database->connect();
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM users');
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? (int)$result['count'] : 0;
    }

    public function getAllPatientsAdmin(): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("SELECT p.id as patient_id, u.username FROM patients p JOIN users u ON p.id_user = u.id ORDER BY u.username ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllDoctorsAdmin(): array {
        $db = $this->database->connect();
        $stmt = $db->prepare("SELECT d.id as doctor_id, u.username FROM doctors d JOIN users u ON d.id_user = u.id ORDER BY u.username ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllUsersWithRoles(): array {
        $db = $this->database->connect();
        $stmt = $db->prepare('
            SELECT u.id, u.email, u.username, r.name as role,
                   p.pesel,
                   (SELECT s.name 
                    FROM doctors_specializations ds 
                    JOIN specializations s ON ds.id_specialization = s.id 
                    WHERE ds.id_doctor = d.id LIMIT 1) as specialization
            FROM users u 
            JOIN roles r ON u.id_role = r.id 
            LEFT JOIN patients p ON p.id_user = u.id
            LEFT JOIN doctors d ON d.id_user = u.id
            ORDER BY u.id DESC
        ');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateUserAdmin(int $id, string $username, string $email, string $password, string $role, string $pesel) {
        $db = $this->database->connect();
        
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id FROM roles WHERE name = ?");
            $stmt->execute([$role]);
            $roleId = $stmt->fetchColumn();

            if (!empty($password)) {
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare('UPDATE users SET username = ?, email = ?, password = ?, id_role = ? WHERE id = ?');
                $stmt->execute([$username, $email, $hashedPassword, $roleId, $id]);
            } else {
                $stmt = $db->prepare('UPDATE users SET username = ?, email = ?, id_role = ? WHERE id = ?');
                $stmt->execute([$username, $email, $roleId, $id]);
            }

            if ($role === 'patient' && !empty($pesel)) {
                $stmt = $db->prepare('UPDATE patients SET pesel = ? WHERE id_user = ?');
                $stmt->execute([$pesel, $id]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // --- METODY DO USUWANIA UŻYTKOWNIKÓW ---
    
    public function getAdminCount(): int {
        $db = $this->database->connect();
        $stmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.id_role = r.id WHERE r.name = 'admin'");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function deleteUserAdmin(int $id): void {
        $db = $this->database->connect();
        try {
            $db->beginTransaction();
            
            $stmt1 = $db->prepare('DELETE FROM appointments WHERE id_patient IN (SELECT id FROM patients WHERE id_user = ?) OR id_doctor IN (SELECT id FROM doctors WHERE id_user = ?)');
            $stmt1->execute([$id, $id]);

            $stmt2 = $db->prepare('DELETE FROM doctors_specializations WHERE id_doctor IN (SELECT id FROM doctors WHERE id_user = ?)');
            $stmt2->execute([$id]);

            $stmt3 = $db->prepare('DELETE FROM patients WHERE id_user = ?');
            $stmt3->execute([$id]);

            $stmt4 = $db->prepare('DELETE FROM doctors WHERE id_user = ?');
            $stmt4->execute([$id]);

            $stmt5 = $db->prepare('DELETE FROM users WHERE id = ?');
            $stmt5->execute([$id]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}