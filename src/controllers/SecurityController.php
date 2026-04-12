<?php

require_once 'AppController.php';

class SecurityController extends AppController {

    public function login() {
        // TODO sprawdzeie czy user istnieje

        if ($this->isPost()) {
            // return $this->render("dashboard");

            var_dump($_POST);
            //

            $url = "http://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/dashboard");
        }

        return $this->render("login");
    }

    public function register() {
        if ($this->isPost()) {
            // todo zarejestruj usera
            var_dump($_POST);
        }

        return $this->render("register");
    }
}