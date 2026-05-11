<?php

require_once 'Repository.php';

class DoctorRepository extends Repository {

    public function searchDoctors(string $searchString): array {
        $searchString = '%' . strtolower($searchString) . '%';

        $stmt = $this->database->connect()->prepare('
            SELECT * FROM view_doctor_search 
            WHERE LOWER(name) LIKE :search OR LOWER(specializations) LIKE :search
        ');
        $stmt->bindParam(':search', $searchString, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}