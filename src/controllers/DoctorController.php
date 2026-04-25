<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/DoctorRepository.php';

class DoctorController extends AppController {

    // metoda ładująca cały widok strony wyszukiwarki
    public function findDoctor() {
        $this->requireLogin();
        return $this->render('find_doctor');
    }

    // metoda odpowiadająca na Fetch API
    public function search() {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

        if ($contentType === "application/json") {
            $content = trim(file_get_contents("php://input"));
            $decoded = json_decode($content, true);

            $doctorRepository = new DoctorRepository();
            header('Content-type: application/json');
            http_response_code(200);

            echo json_encode($doctorRepository->searchDoctors($decoded['search']));
        }
    }
}