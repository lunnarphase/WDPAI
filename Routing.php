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
        "my-appointments" => [
            "controller" => "DashboardController",
            "action" => "myAppointments"
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
        "admin-update-user" => [
            "controller" => "AdminController",
            "action" => "adminUpdateUser"
        ],
        "admin-delete-user" => [
            "controller" => "AdminController",
            "action" => "adminDeleteUser"
        ],
        "admin-add-user" => [
            "controller" => "AdminController",
            "action" => "adminAddUser"
        ],
        "doctor-dashboard" => [
            "controller" => "DoctorController",
            "action" => "doctorDashboard"
        ],
        "doctor-update-status" => [
            "controller" => "DoctorController",
            "action" => "doctorUpdateStatus"
        ],
        "api-search-doctors" => [
            "controller" => "DashboardController",
            "action" => "apiSearchDoctors"
        ],
        "api-get-appointments" => [
            "controller" => "DashboardController",
            "action" => "apiGetAppointments"
        ],
        "api-get-slots" => [
            "controller" => "DoctorController",
            "action" => "apiGetSlots"        ],
        "api-mark-notifications-read" => [
            "controller" => "DashboardController",
            "action" => "apiMarkNotificationsRead"
        ],
        "api-clear-notifications" => [
            "controller" => "DashboardController",
            "action" => "apiClearNotifications"        ],
        "api-get-profile" => [
            "controller" => "DashboardController",
            "action" => "apiGetProfile"
        ],
        "api-update-profile" => [
            "controller" => "DashboardController",
            "action" => "apiUpdateProfile"
        ],
    ];

    public static function run(string $path) {
        if (array_key_exists($path, self::$routes)) {
            $controller = self::$routes[$path]["controller"];
            $action = self::$routes[$path]["action"];

            $controllerObj = new $controller;
            $id = null;

            try {
                $controllerObj->$action($id);
            } catch (\Exception $e) {
                error_log($e->getMessage());
                http_response_code(500);
                include 'public/views/500.html';
            }
        } else {
            http_response_code(404);
            include 'public/views/404.html';
        }
    }
}