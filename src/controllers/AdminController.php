<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AppointmentRepository.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';

class AdminController extends AppController {

    public function adminDashboard() {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard"); exit();
        }

        $appointmentRepo = new AppointmentRepository();
        $userRepo = new UsersRepository();
        
        return $this->render('admin_dashboard', [
            'appointments' => $appointmentRepo->getAllAppointmentsAdmin(),
            'todayCount' => $appointmentRepo->getTodaysAppointmentsCount(),
            'usersCount' => $userRepo->getUsersCount(),
            'patients' => $userRepo->getAllPatientsAdmin(),
            'doctors' => $userRepo->getAllDoctorsAdmin()
        ]);
    }

    public function adminUpdateAppointment() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard"); exit();
        }

        if ($this->isPost()) {
            $id = (int)$_POST['appointment_id'];
            $patientId = (int)$_POST['patient_id'];
            $doctorId = (int)$_POST['doctor_id'];
            $date = $_POST['appointment_date'];
            $time = $_POST['appointment_time'];
            $status = $_POST['status'];
            
            $repo = new AppointmentRepository();
            $repo->updateAppointmentAdmin($id, $patientId, $doctorId, $date, $time, $status);
        }
        header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard"); exit();
    }

    public function adminDeleteAppointment() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            header("Location: http://$_SERVER[HTTP_HOST]/dashboard"); exit();
        }

        if ($this->isPost()) {
            $id = (int)$_POST['appointment_id'];
            $repo = new AppointmentRepository();
            $repo->deleteAppointmentAdmin($id);
        }
        header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard"); exit();
    }
}