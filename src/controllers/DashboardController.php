<?php

require_once 'AppController.php';

class DashboardController extends AppController {

    public function index() {
        // Wymagamy zalogowania!
        $this->requireLogin();

        $title = "DASHBOARD - MediSchedule";
        return $this->render("dashboard", ["title" => $title]);
    }
}