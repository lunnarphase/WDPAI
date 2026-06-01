<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/repositories/UsersRepository.php';

class SqliteUsersRepositoryDatabase extends Database
{
    private ?PDO $connection = null;

    public function __construct()
    {
    }

    public function connect()
    {
        if ($this->connection === null) {
            $this->connection = new PDO('sqlite::memory:');
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->bootstrapSchema($this->connection);
        }

        return $this->connection;
    }

    private function bootstrapSchema(PDO $db): void
    {
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)');
        $db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            username TEXT NOT NULL,
            id_role INTEGER NOT NULL,
            is_blocked INTEGER NOT NULL DEFAULT 0
        )');
        $db->exec('CREATE TABLE patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_user INTEGER NOT NULL,
            pesel TEXT
        )');
        $db->exec('CREATE TABLE doctors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_user INTEGER NOT NULL,
            bio TEXT,
            visit_price REAL,
            visit_duration INTEGER
        )');
        $db->exec('CREATE TABLE specializations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE doctors_specializations (
            id_doctor INTEGER NOT NULL,
            id_specialization INTEGER NOT NULL
        )');
        $db->exec('CREATE TABLE appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_patient INTEGER NOT NULL,
            id_doctor INTEGER NOT NULL,
            appointment_date TEXT,
            appointment_time TEXT,
            status TEXT
        )');

        $db->exec("INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'doctor'), (3, 'patient')");

        $this->insertUser($db, 'admin@example.com', 'Admin#12345', 'admin_user', 1, 0);
        $this->insertUser($db, 'doctor@example.com', 'Doctor#12345', 'doctor_user', 2, 0);
        $this->insertUser($db, 'patient@example.com', 'Patient#12345', 'patient_user', 3, 0);
        $this->insertUser($db, 'blocked@example.com', 'Blocked#12345', 'blocked_user', 3, 1);

        $db->exec("INSERT INTO patients (id_user, pesel) VALUES (3, '01234567890')");
        $db->exec("INSERT INTO doctors (id_user, bio, visit_price, visit_duration) VALUES (2, 'Cardiologist', 150.0, 30)");
        $db->exec("INSERT INTO specializations (name) VALUES ('Cardiology')");
        $db->exec("INSERT INTO doctors_specializations (id_doctor, id_specialization) VALUES (1, 1)");
        $db->exec("INSERT INTO appointments (id_patient, id_doctor, appointment_date, appointment_time, status) VALUES (1, 1, '2026-01-15', '10:00', 'confirmed')");
    }

    private function insertUser(PDO $db, string $email, string $plainPassword, string $username, int $roleId, int $blocked): void
    {
        $stmt = $db->prepare('INSERT INTO users (email, password, username, id_role, is_blocked) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $email,
            password_hash($plainPassword, PASSWORD_BCRYPT),
            $username,
            $roleId,
            $blocked,
        ]);
    }
}

class UsersRepositoryTest extends TestCase
{
    private UsersRepository $repository;
    private PDO $pdo;

    protected function setUp(): void
    {
        $database = new SqliteUsersRepositoryDatabase();
        $this->repository = new UsersRepository($database);
        $this->pdo = $database->connect();
    }

    public function testGetUserByEmailReturnsMappedDomainModel(): void
    {
        $user = $this->repository->getUserByEmail('blocked@example.com');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('blocked@example.com', $user->getEmail());
        $this->assertSame('blocked_user', $user->getUsername());
        $this->assertSame('patient', $user->getRole());
        $this->assertTrue($user->getIsBlocked());
    }

    public function testGetUserByEmailReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository->getUserByEmail('missing@example.com'));
    }

    public function testEmailExistsReturnsExpectedState(): void
    {
        $this->assertTrue($this->repository->emailExists('admin@example.com'));
        $this->assertFalse($this->repository->emailExists('nobody@example.com'));
    }

    public function testBlockAndUnblockUserFlipBlockedFlag(): void
    {
        $this->repository->blockUser(3);
        $blocked = (int)$this->pdo->query('SELECT is_blocked FROM users WHERE id = 3')->fetchColumn();
        $this->assertSame(1, $blocked);

        $this->repository->unblockUser(3);
        $unblocked = (int)$this->pdo->query('SELECT is_blocked FROM users WHERE id = 3')->fetchColumn();
        $this->assertSame(0, $unblocked);
    }

    public function testGetEmailByIdReturnsEmailOrEmptyString(): void
    {
        $this->assertSame('doctor@example.com', $this->repository->getEmailById(2));
        $this->assertSame('', $this->repository->getEmailById(999));
    }

    public function testGetAdminUserIdsReturnsOnlyAdminAccounts(): void
    {
        $ids = $this->repository->getAdminUserIds();

        $this->assertSame([1], array_map('intval', $ids));
    }

    public function testGetUsersCountReflectsAllRows(): void
    {
        $this->assertSame(4, $this->repository->getUsersCount());
    }

    public function testGetAdminCountReturnsExpectedValue(): void
    {
        $this->assertSame(1, $this->repository->getAdminCount());
    }

    public function testGetAllPatientsAdminReturnsSortedPatientList(): void
    {
        $patients = $this->repository->getAllPatientsAdmin();

        $this->assertCount(1, $patients);
        $this->assertSame('patient_user', $patients[0]['username']);
        $this->assertSame('1', (string)$patients[0]['patient_id']);
    }

    public function testGetAllDoctorsAdminReturnsSortedDoctorList(): void
    {
        $doctors = $this->repository->getAllDoctorsAdmin();

        $this->assertCount(1, $doctors);
        $this->assertSame('doctor_user', $doctors[0]['username']);
        $this->assertSame('1', (string)$doctors[0]['doctor_id']);
    }

    public function testGetUserProfileDataReturnsDataAndNullForMissingUser(): void
    {
        $profile = $this->repository->getUserProfileData(3);

        $this->assertNotNull($profile);
        $this->assertSame('patient@example.com', $profile['email']);
        $this->assertSame('patient', $profile['role']);
        $this->assertSame('01234567890', $profile['pesel']);

        $this->assertNull($this->repository->getUserProfileData(999));
    }

    public function testGetAllUsersWithRolesIncludesSpecializationAndPesel(): void
    {
        $rows = $this->repository->getAllUsersWithRoles();

        $this->assertCount(4, $rows);

        $doctorRow = $this->findByEmail($rows, 'doctor@example.com');
        $this->assertNotNull($doctorRow);
        $this->assertSame('doctor', $doctorRow['role']);
        $this->assertSame('Cardiology', $doctorRow['specialization']);

        $patientRow = $this->findByEmail($rows, 'patient@example.com');
        $this->assertNotNull($patientRow);
        $this->assertSame('01234567890', $patientRow['pesel']);
    }

    public function testUpdateUserProfileDataWithoutPassword(): void
    {
        $updated = $this->repository->updateUserProfileData(3, 'updated@example.com', 'new_patient_name', null);

        $this->assertTrue($updated);

        $stmt = $this->pdo->query("SELECT email, username FROM users WHERE id = 3");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('updated@example.com', $row['email']);
        $this->assertSame('new_patient_name', $row['username']);
    }

    public function testUpdateUserProfileDataWithPasswordHashesIt(): void
    {
        $updated = $this->repository->updateUserProfileData(3, 'patient@example.com', 'patient_user', 'NewPass#12345');

        $this->assertTrue($updated);

        $hashed = (string)$this->pdo->query('SELECT password FROM users WHERE id = 3')->fetchColumn();
        $this->assertTrue(password_verify('NewPass#12345', $hashed));
    }

    public function testUpdateUserAdminUpdatesPatientDataWithoutChangingPasswordWhenEmpty(): void
    {
        $oldHash = (string)$this->pdo->query('SELECT password FROM users WHERE id = 3')->fetchColumn();

        $this->repository->updateUserAdmin(3, 'patient_after_admin', 'patient.updated@example.com', '', 'patient', '99887766554');

        $stmt = $this->pdo->query('SELECT username, email, password, id_role FROM users WHERE id = 3');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('patient_after_admin', $row['username']);
        $this->assertSame('patient.updated@example.com', $row['email']);
        $this->assertSame($oldHash, $row['password']);
        $this->assertSame('3', (string)$row['id_role']);

        $pesel = (string)$this->pdo->query('SELECT pesel FROM patients WHERE id_user = 3')->fetchColumn();
        $this->assertSame('99887766554', $pesel);
    }

    public function testUpdateUserAdminHashesPasswordWhenProvided(): void
    {
        $oldHash = (string)$this->pdo->query('SELECT password FROM users WHERE id = 2')->fetchColumn();

        $this->repository->updateUserAdmin(2, 'doctor_after_admin', 'doctor.updated@example.com', 'DoctorNew#77', 'doctor', '');

        $stmt = $this->pdo->query('SELECT username, email, password, id_role FROM users WHERE id = 2');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('doctor_after_admin', $row['username']);
        $this->assertSame('doctor.updated@example.com', $row['email']);
        $this->assertSame('2', (string)$row['id_role']);
        $this->assertNotSame($oldHash, $row['password']);
        $this->assertTrue(password_verify('DoctorNew#77', $row['password']));
    }

    public function testUpdateUserProfileDataReturnsFalseWhenConstraintFails(): void
    {
        $updated = $this->repository->updateUserProfileData(3, 'admin@example.com', 'patient_user', null);

        $this->assertFalse($updated);
    }

    public function testUpdateUserPasswordStoresProvidedHash(): void
    {
        $hash = password_hash('TotallyNew#1', PASSWORD_BCRYPT);

        $this->assertTrue($this->repository->updateUserPassword(2, $hash));

        $storedHash = (string)$this->pdo->query('SELECT password FROM users WHERE id = 2')->fetchColumn();
        $this->assertSame($hash, $storedHash);
    }

    public function testDeleteUserAdminRemovesUserAndRelatedDoctorData(): void
    {
        $this->repository->deleteUserAdmin(2);

        $doctorUserExists = (int)$this->pdo->query('SELECT COUNT(*) FROM users WHERE id = 2')->fetchColumn();
        $doctorRowExists = (int)$this->pdo->query('SELECT COUNT(*) FROM doctors WHERE id_user = 2')->fetchColumn();
        $doctorSpecsExist = (int)$this->pdo->query('SELECT COUNT(*) FROM doctors_specializations')->fetchColumn();
        $doctorAppointments = (int)$this->pdo->query('SELECT COUNT(*) FROM appointments WHERE id_doctor = 1')->fetchColumn();

        $this->assertSame(0, $doctorUserExists);
        $this->assertSame(0, $doctorRowExists);
        $this->assertSame(0, $doctorSpecsExist);
        $this->assertSame(0, $doctorAppointments);
    }

    private function findByEmail(array $rows, string $email): ?array
    {
        foreach ($rows as $row) {
            if (($row['email'] ?? null) === $email) {
                return $row;
            }
        }

        return null;
    }
}
