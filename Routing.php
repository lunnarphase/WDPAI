<?php

require_once 'src/controllers/SecurityController.php';
require_once 'src/controllers/DashboardController.php';
require_once 'src/controllers/DoctorController.php';
require_once 'src/controllers/AppointmentController.php';
require_once 'src/controllers/AdminController.php';

class Routing {

    public static $routes = [
        "login" => [
            "controller" => "SecurityController",
            "action" => "login"
        ],
        "dashboard" => [
            "controller" => "DashboardController",
            "action" => "index"
        ],
        "admin-dashboard" => [
            "controller" => "AdminController",
            "action" => "adminDashboard"
        ],
        "" => [
            "controller" => "SecurityController",
            "action" => "login"
        ],
        "register" => [
            "controller" => "SecurityController",
            "action" => "register"
        ],
        "logout" => [
            "controller" => "SecurityController",
            "action" => "logout"
        ],
        "find-doctor" => [
            "controller" => "DoctorController",
            "action" => "findDoctor"
        ],
        "search" => [
            "controller" => "DoctorController",
            "action" => "search"
        ],
        "book-appointment" => [
            "controller" => "AppointmentController",
            "action" => "book"
        ],
        "confirm-appointment" => [
            "controller" => "AppointmentController",
            "action" => "confirm"
        ],
        "cancel-appointment" => [
            "controller" => "AppointmentController",
            "action" => "cancel"
        ],
        "doctor-availability" => [
            "controller" => "AppointmentController",
            "action" => "getAvailability"
        ],
        "admin-update-appointment" => [
            "controller" => "AdminController",
            "action" => "adminUpdateAppointment"
        ],
        "admin-delete-appointment" => [
            "controller" => "AdminController",
            "action" => "adminDeleteAppointment"
        ],
    ];

    public static function run(string $path) {
        switch($path) {
            case 'dashboard':
            case 'admin-dashboard': 
            case '':
            case 'login':
            case 'register':
            case 'find-doctor':
            case 'search':
            case 'book-appointment':
            case 'confirm-appointment':
            case 'cancel-appointment':
            case 'doctor-availability':
            case 'admin-update-appointment':
            case 'admin-delete-appointment':
            case 'logout':
                $controller = Routing::$routes[$path]["controller"];
                $action = Routing::$routes[$path]["action"];

                $controllerObj = new $controller;
                $id = null;

                $controllerObj->$action($id);
                break; 
            default:
                include 'public/views/404.html';
                break;
        }
    }
}