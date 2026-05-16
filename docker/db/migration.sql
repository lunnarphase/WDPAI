-- Migration: add trigger for review requests
CREATE OR REPLACE FUNCTION notify_patient_review_request()
RETURNS TRIGGER AS $$
DECLARE
    v_patient_user_id INTEGER;
    v_doctor_name VARCHAR;
BEGIN
    IF NEW.status = 'completed' AND (OLD.status IS NULL OR OLD.status != 'completed') THEN
        SELECT p.id_user INTO v_patient_user_id
        FROM patients p WHERE p.id = NEW.id_patient;

        SELECT u.username INTO v_doctor_name
        FROM doctors d JOIN users u ON d.id_user = u.id
        WHERE d.id = NEW.id_doctor;

        IF v_patient_user_id IS NOT NULL THEN
            INSERT INTO notifications (id_user, message, type, related_id)
            VALUES (
                v_patient_user_id,
                'Twoja wizyta u dr ' || v_doctor_name || ' dobie gla konca. Podziel sie opinia i pomoz innym pacjentom wybrac odpowiedniego specjaliste.',
                'review_request',
                NEW.id
            );
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_review_request ON appointments;
CREATE TRIGGER trg_review_request
AFTER UPDATE ON appointments
FOR EACH ROW
EXECUTE FUNCTION notify_patient_review_request();
