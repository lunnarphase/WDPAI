<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/AppointmentRepository.php';
require_once __DIR__ . '/../repositories/UsersRepository.php';

class AdminController extends AppController {

    private $appointmentRepo;
    private $userRepo;

    public function __construct(AppointmentRepository $appointmentRepo = null, UsersRepository $userRepo = null) {
        parent::__construct();
        $this->appointmentRepo = $appointmentRepo ?: new AppointmentRepository();
        $this->userRepo = $userRepo ?: new UsersRepository();
    }
    
    private function requireAdmin() {
        $this->requireLogin();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            $this->forbidden();
        }
    }

    public function adminDashboard() {
        $this->requireAdmin();
        
        return $this->render('admin_dashboard', [
            'appointments' => $this->appointmentRepo->getAllAppointmentsAdmin(),
            'todayCount' => $this->appointmentRepo->getTodaysAppointmentsCount(),
            'usersCount' => $this->userRepo->getUsersCount(),
            'patients' => $this->userRepo->getAllPatientsAdmin(),
            'doctors' => $this->userRepo->getAllDoctorsAdmin(),
            'users' => $this->userRepo->getAllUsersWithRoles()
        ]);
    }

    public function adminUpdateAppointment() {
        $this->requireAdmin();

        if ($this->isPost()) {
            if (empty($_POST['appointment_id']) || empty($_POST['patient_id']) || empty($_POST['doctor_id']) || empty($_POST['appointment_date'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['appointment_id'];
            $patientId = (int)$_POST['patient_id'];
            $doctorId = (int)$_POST['doctor_id'];
            $date = $_POST['appointment_date'];
            $time = $_POST['appointment_time'];
            $status = $_POST['status'];
            
            $this->appointmentRepo->updateAppointmentAdmin($id, $patientId, $doctorId, $date, $time, $status);
        }
        header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard"); exit();
    }

    public function adminDeleteAppointment() {
        $this->requireAdmin();

        if ($this->isPost()) {
            if (empty($_POST['appointment_id'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['appointment_id'];
            $this->appointmentRepo->deleteAppointmentAdmin($id);
        }
        header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard"); exit();
    }

    public function adminUpdateUser() {
        $this->requireAdmin();

        if ($this->isPost()) {
            if (empty($_POST['user_id']) || empty($_POST['username']) || empty($_POST['email']) || empty($_POST['role'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['user_id'];
            $username = $_POST['username'];
            $email = $_POST['email'];
            $password = $_POST['password'] ?? ''; 
            $role = $_POST['role'];
            $pesel = $_POST['pesel'] ?? '';
            
            $this->userRepo->updateUserAdmin($id, $username, $email, $password, $role, $pesel);
        }
        
        header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard"); 
        exit();
    }

    public function adminDeleteUser() {
        $this->requireAdmin();

        if ($this->isPost()) {
            if (empty($_POST['user_id']) || empty($_POST['user_role'])) {
                $this->badRequest();
            }
            $id = (int)$_POST['user_id'];
            $role = $_POST['user_role'];

            if ($id === $_SESSION['user_id']) {
                header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard?error=self_delete");
                exit();
            }

            if ($role === 'admin' && $this->userRepo->getAdminCount() <= 1) {
                header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard?error=last_admin");
                exit();
            }

            $this->userRepo->deleteUserAdmin($id);
        }
        
        header("Location: http://$_SERVER[HTTP_HOST]/admin-dashboard"); 
        exit();
    }
}