<?php

require_once 'AppController.php';

class DashboardController extends AppController {

    public function index() {
        // TODO pobieranie danych z bazy
        // wstawianie zmiennych na widok
        $title = "INDEX";

        return $this->render("index", ["title" => $title]);
    }
}