<?php

require_once 'Repository.php';

class DoctorRepository extends Repository {

    public function searchDoctors(string $searchString): array {
        // Dodajemy % do wyszukiwania (tzw. wildcard w SQL)
        $searchString = '%' . strtolower($searchString) . '%';

        // Szukamy po nazwisku (username) lub po specjalizacji
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM view_doctor_search 
            WHERE LOWER(name) LIKE :search OR LOWER(specializations) LIKE :search
        ');
        $stmt->bindParam(':search', $searchString, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}