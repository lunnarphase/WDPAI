<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/User.php';

class UsersRepository extends Repository {

    public function getUserByEmail(string $email): ?User {
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
            $db->beginTransaction();

            $stmt = $db->prepare('
                INSERT INTO users (email, password, username, id_role)
                VALUES (?, ?, ?, 3) RETURNING id
            ');
            $stmt->execute([$email, $password, $username]);

            $userId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];

            $stmt = $db->prepare('
                INSERT INTO patients (id_user, pesel)
                VALUES (?, ?)
            ');
            $stmt->execute([$userId, $pesel]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
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

    public function searchDoctors(string $keyword, string $specialization): array {
        $db = $this->database->connect();

        $sql = "
            SELECT d.id as doctor_id, u.username as name, s.name as specialization,
                   d.visit_price, d.visit_duration,
                   COALESCE(ROUND(AVG(r.rating)::NUMERIC, 1), 0) as avg_rating,
                   COUNT(r.id) as review_count
            FROM doctors d
            JOIN users u ON d.id_user = u.id
            LEFT JOIN doctors_specializations ds ON d.id = ds.id_doctor
            LEFT JOIN specializations s ON ds.id_specialization = s.id
            LEFT JOIN reviews r ON d.id = r.id_doctor
            WHERE u.username ILIKE :keyword
        ";

        if ($specialization !== 'all') {
            $sql .= " AND s.name = :specialization";
        }

        $sql .= " GROUP BY d.id, u.username, s.name, d.visit_price, d.visit_duration ORDER BY avg_rating DESC NULLS LAST";

        $stmt = $db->prepare($sql);
        $searchKeyword = '%' . $keyword . '%';
        $stmt->bindParam(':keyword', $searchKeyword, PDO::PARAM_STR);

        if ($specialization !== 'all') {
            $stmt->bindParam(':specialization', $specialization, PDO::PARAM_STR);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addUserAdmin(string $email, string $password, string $username, string $role, string $pesel): bool {
        $conn = $this->database->connect();
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare('SELECT id FROM roles WHERE name = :role_name');
            $stmt->bindParam(':role_name', $role, PDO::PARAM_STR);
            $stmt->execute();
            $roleData = $stmt->fetch(PDO::FETCH_ASSOC);

            $roleId = $roleData ? $roleData['id'] : 3;

            $stmt = $conn->prepare('
                INSERT INTO users (email, password, username, id_role)
                VALUES (?, ?, ?, ?) RETURNING id
            ');

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt->execute([
                $email,
                $hashedPassword,
                $username,
                $roleId
            ]);
            
            $userId = $stmt->fetchColumn();

            if ($role === 'patient') {
                $stmt = $conn->prepare('INSERT INTO patients (id_user, pesel) VALUES (?, ?)');
                $stmt->execute([$userId, $pesel]);
            } else if ($role === 'doctor') {
                $bio = "Brak bio";
                $stmt = $conn->prepare('INSERT INTO doctors (id_user, bio) VALUES (?, ?)');
                $stmt->execute([$userId, $bio]);
            }

            $conn->commit();
            return true;

        } catch (Exception $e) {
            $conn->rollBack();
            return false;
        }
    }

    public function getUserProfileData(int $userId): ?array {
        $db = $this->database->connect();
        $stmt = $db->prepare("
            SELECT u.email, u.username, r.name as role, p.pesel 
            FROM users u
            JOIN roles r ON u.id_role = r.id
            LEFT JOIN patients p ON u.id = p.id_user
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    public function updateUserProfileData(int $userId, string $email, string $username, ?string $password): bool {
        $db = $this->database->connect();
        try {
            if ($password) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET email = ?, username = ?, password = ? WHERE id = ?");
                $stmt->execute([$email, $username, $hash, $userId]);
            } else {
                $stmt = $db->prepare("UPDATE users SET email = ?, username = ? WHERE id = ?");
                $stmt->execute([$email, $username, $userId]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function updateUserPassword(int $userId, string $hashedPassword): bool {
        $db = $this->database->connect();
        try {
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}