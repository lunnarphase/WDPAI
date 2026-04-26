<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';

class SecurityController extends AppController {

    public function login() {
        // zabezpieczenie ścieżki - jeśli jesteśmy zalogowani, wracamy do panelu
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user_id'])) {
            $url = "http://$_SERVER[HTTP_HOST]";
            // Jeśli ktoś jest już zalogowany, kierujemy go na podstawie roli (zabezpieczenie)
            if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
                header("Location: {$url}/admin-dashboard");
            } else {
                header("Location: {$url}/dashboard");
            }
            exit();
        }

        if (!$this->isPost()) {
            return $this->render('login');
        }

        $email = $_POST["email"] ?? '';
        $password = $_POST["password"] ?? '';

        if (empty($email) || empty($password)) {
            return $this->render('login', ['messages' => ['Wypełnij wszystkie pola']]);
        }

        $userRepository = new UsersRepository();
        $user = $userRepository->getUserByEmail($email);

        if (!$user) {
            return $this->render('login', ['messages' => ['Nie znaleziono użytkownika']]);
        }

        if (!password_verify($password, $user->getPassword())) {
            return $this->render('login', ['messages' => ['Błędne hasło']]);
        }

        // obsługa opcji "Zapamiętaj mnie"
        if (isset($_POST['remember'])) {
            // zapis ciasteczka z mailem na 30 dni
            setcookie('remember_email', $user->getEmail(), time() + (86400 * 30), "/");
        } else {
            // jeśli odznaczono opcję, usuwamy ciasteczko 
            setcookie('remember_email', '', time() - 3600, "/");
        }

        // Tworzymy sesję
        session_regenerate_id(true); 
        $_SESSION['user_id'] = $user->getId();
        $_SESSION['user_email'] = $user->getEmail();
        $_SESSION['user_name'] = $user->getUsername();
        $_SESSION['user_role'] = $user->getRole();
        $_SESSION['is_logged_in'] = true;

        // Przekierowanie na podstawie ról (TUTAJ BYŁ BRAKUJĄCY NAWIAS)
        if ($user->getRole() === 'admin') {
            $url = "http://$_SERVER[HTTP_HOST]/admin-dashboard";
            header("Location: {$url}");
            exit();
        } elseif ($user->getRole() === 'doctor') {
            $url = "http://$_SERVER[HTTP_HOST]/doctor-dashboard";
            header("Location: {$url}");
            exit();
        } else {
            // Domyślnie pacjent
            $url = "http://$_SERVER[HTTP_HOST]/dashboard";
            header("Location: {$url}");
            exit();        
        } // <-- TEGO NAWIASU BRAKOWAŁO
    }

    public function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        
        $url = "http://$_SERVER[HTTP_HOST]";
        header("Location: {$url}/login");
        exit();
    }

    public function register() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['user_id'])) {
            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/dashboard");
            exit();
        }

        if (!$this->isPost()) {
            return $this->render('register');
        }

        $email = $_POST['email'];
        $password = $_POST['password'];
        $confirmedPassword = $_POST['password_confirm'];
        $name = $_POST['name'];
        $surname = $_POST['surname'];
        $pesel = $_POST['pesel'];

        if ($password !== $confirmedPassword) {
            return $this->render('register', ['messages' => ['Hasła nie są identyczne!']]);
        }

        // Proste generowanie username z imienia i nazwiska
        $username = strtolower($name . $surname);

        $userRepository = new UsersRepository();
        
        try {
            $userRepository->createUser(
                $email, 
                password_hash($password, PASSWORD_BCRYPT), 
                $username, 
                $name, 
                $surname, 
                $pesel
            );
            return $this->render('login', ['messages' => ['Konto założone! Możesz się zalogować.'], 'is_success' => true]);
        } catch (Exception $e) {
            return $this->render('register', ['messages' => ['Błąd rejestracji. PESEL lub Email może już istnieć.']]);
        }
    }
}