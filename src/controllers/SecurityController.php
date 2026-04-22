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


        if (!password_verify($password, $user->getPassword())) {
            return $this->render('login', ['messages' => 'Błędne hasło']);
        }

        session_regenerate_id(true); 

        $_SESSION['user_id'] = $user->getId();
        $_SESSION['user_email'] = $user->getEmail();
        $_SESSION['user_name'] = $user->getUsername();
        $_SESSION['user_role'] = $user->getRole(); 
        $_SESSION['is_logged_in'] = true;

        $url = "http://$_SERVER[HTTP_HOST]";
        header("Location: {$url}/dashboard");
        exit();
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

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $userRepository->createUser($email, $hashedPassword, $username);

            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/login");
            return;
        }

        return $this->render("register");
    }
}