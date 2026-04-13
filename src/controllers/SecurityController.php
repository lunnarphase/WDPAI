<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';

class SecurityController extends AppController {

    public function login() {
        if (!$this->isPost()) {
            return $this->render('login');
        }

        $email = $_POST["email"] ?? '';
        $password = $_POST["password"] ?? '';

        if (empty($email) || empty($password)) {
            return $this->render('login', ['messages' => 'Wypełnij wszystkie pola']);
        }

        $userRepository = new UsersRepository();
        $user = $userRepository->getUserByEmail($email);
    
        if (!$user) {
            return $this->render('login', ['messages' => 'Nie znaleziono użytkownika']);
        }

        if (!password_verify($password, $user['password'])) {
            return $this->render('login', ['messages' => 'Błędne hasło']);
        }

        // Zapisujemy email użytkownika do sesji / ciastka, żeby go zapamiętać jako zalogowanego
        setcookie("username", $user['email'], time() + 3600, '/');

        $url = "http://$_SERVER[HTTP_HOST]";
        header("Location: {$url}/dashboard");
    }

    public function register() {
        $userRepository = new UsersRepository();

        if ($this->isPost()) {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';
            $username = trim($_POST['username'] ?? '');

            if (empty($email) || empty($password) || empty($username)) {
                return $this->render('register', ['messages' => 'Wypełnij wszystkie pola']);
            }

            if ($password !== $password2) {
                return $this->render('register', ['messages' => 'Podane hasła nie są identyczne']);
            }

            $user = $userRepository->getUserByEmail($email);
            if ($user) {
                return $this->render("register", ["messages" => "Użytkownik z podanym adresem e-mail już istnieje"]);
            }

            // Tworzenie użytkownika i hashowanie hasła
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $userRepository->createUser($email, $hashedPassword, $username);

            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/login");
            return;
        }

        return $this->render("register");
    }
}