Admin - admin@admin / admin

User - test1@gmail.com / test1

Lekarze:
kowalski@med.pl   / admin
nowak@med.pl      / admin
wisniewski@med.pl / admin


Podgląd widoku umówionych wizyt:
docker exec -it wdpai-db-1 psql -U docker -d db
SELECT * FROM view_appointment_details;