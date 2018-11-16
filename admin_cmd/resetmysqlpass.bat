SET mysql_dir=C:\Bitnami\wampstack-5.6.20-0\mysql\bin
%mysql_dir%\mysqld --console --defaults-file="C:\\Bitnami\\wampstack-5.6.20-0\\mysql\\my.ini" --init-file=mysql-init.txt
pause
%mysql_dir%\mysqld --console
pause
