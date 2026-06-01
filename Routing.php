<?php

require_once 'src/controllers/SecurityController.php';
require_once 'src/controllers/DashboardController.php';
require_once 'src/controllers/DoctorController.php';
require_once 'src/controllers/AppointmentController.php';
require_once 'src/controllers/AdminController.php';
require_once 'src/controllers/ReviewController.php';

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
        "debug-unblock-all" => [
            "controller" => "SecurityController",
            "action" => "debugUnblockAll"
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
            "action" => "apiGetSlots"
        ],
        "api-get-doctor-appointments" => [
            "controller" => "DoctorController",
            "action" => "apiGetDoctorAppointments"
        ],
        "api-get-my-reviews" => [
            "controller" => "DoctorController",
            "action" => "apiGetMyReviews"
        ],
        "api-get-week-availability" => [
            "controller" => "DoctorController",
            "action" => "apiGetWeekAvailability"
        ],
        "api-save-week-availability" => [
            "controller" => "DoctorController",
            "action" => "apiSaveWeekAvailability"
        ],
        "api-get-schedule-templates" => [
            "controller" => "DoctorController",
            "action" => "apiGetScheduleTemplates"
        ],
        "api-save-schedule-template" => [
            "controller" => "DoctorController",
            "action" => "apiSaveScheduleTemplate"
        ],
        "api-delete-schedule-template" => [
            "controller" => "DoctorController",
            "action" => "apiDeleteScheduleTemplate"
        ],
        "api-get-available-dates" => [
            "controller" => "DoctorController",
            "action" => "apiGetAvailableDates"
        ],
        "api-mark-notifications-read" => [
            "controller" => "DashboardController",
            "action" => "apiMarkNotificationsRead"
        ],
        "api-get-notifications" => [
            "controller" => "DashboardController",
            "action" => "apiGetNotifications"
        ],
        "api-get-notifications-unread" => [
            "controller" => "DashboardController",
            "action" => "apiGetNotificationsUnreadCount"
        ],
        "api-admin-attack-details" => [
            "controller" => "AdminController",
            "action" => "apiAdminAttackDetails"
        ],
        "api-clear-notifications" => [
            "controller" => "DashboardController",
            "action" => "apiClearNotifications"
        ],
        "api-delete-notification" => [
            "controller" => "DashboardController",
            "action" => "apiDeleteNotification"
        ],
        "api-get-profile" => [
            "controller" => "DashboardController",
            "action" => "apiGetProfile"
        ],
        "api-update-profile" => [
            "controller" => "DashboardController",
            "action" => "apiUpdateProfile"
        ],
        "api-change-password" => [
            "controller" => "DashboardController",
            "action" => "apiChangePassword"
        ],
        "submit-review" => [
            "controller" => "ReviewController",
            "action" => "submitReview"
        ],
        "report-review" => [
            "controller" => "ReviewController",
            "action" => "reportReview"
        ],
        "api-doctor-profile" => [
            "controller" => "ReviewController",
            "action" => "apiGetDoctorProfile"
        ],
        "update-doctor-profile" => [
            "controller" => "DoctorController",
            "action" => "updateDoctorProfile"
        ],
        "admin-delete-review" => [
            "controller" => "AdminController",
            "action" => "adminDeleteReview"
        ],
        "admin-dismiss-report" => [
            "controller" => "AdminController",
            "action" => "adminDismissReport"
        ],
        "admin-resolve-report" => [
            "controller" => "AdminController",
            "action" => "adminResolveReport"
        ],
        "api-get-review-reports" => [
            "controller" => "AdminController",
            "action" => "apiGetReviewReports"
        ],
        "api-get-login-logs" => [
            "controller" => "AdminController",
            "action" => "apiGetLoginLogs"
        ],
        "api-admin-appointments" => [
            "controller" => "AdminController",
            "action" => "apiGetAdminAppointments"
        ],
        "api-admin-reviews" => [
            "controller" => "AdminController",
            "action" => "apiGetAdminReviews"
        ],
        "api-admin-notifications" => [
            "controller" => "AdminController",
            "action" => "apiGetAdminNotifications"
        ],
        "admin-block-user" => [
            "controller" => "AdminController",
            "action" => "adminBlockUser"
        ],
        "admin-unblock-user" => [
            "controller" => "AdminController",
            "action" => "adminUnblockUser"
        ],
    ];

    private static function isJsonRequest(string $path): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

        return str_starts_with($path, 'api-')
            || stripos($accept, 'application/json') !== false
            || stripos($contentType, 'application/json') !== false
            || $xrw === 'xmlhttprequest';
    }

    private static function emitJsonError(string $message, int $statusCode): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code($statusCode);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

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

                if (self::isJsonRequest($path)) {
                    self::emitJsonError('Wystąpił błąd serwera.', 500);
                }

                http_response_code(500);
                include 'public/views/500.html';
            }
        } else {
            if (self::isJsonRequest($path)) {
                self::emitJsonError('Nie znaleziono zasobu.', 404);
            }

            http_response_code(404);
            include 'public/views/404.html';
        }
    }
}