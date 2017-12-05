<?php

////////////////
// sie_authsetup messages.
////////////////
$labels['2fa_required'] = "Se requiere establecer un segundo factor de autenticación antes de continuar.";

////////////////
/// kolab_2fa overrides.
////////////////
$kolab2FaLabels['settingslist'] = 'Aut. Multifactor';
$kolab2FaLabels['settingstitle'] = 'Factores de autenticación';
$kolab2FaLabels['addfactor'] = 'Añadir factor de autenticación';
$kolab2FaLabels['totp'] = 'Aplicación móvil (TOTP)';
$kolab2FaLabels['label'] = 'Nombre';
$kolab2FaLabels['qrcode'] = 'Código QR';
$kolab2FaLabels['qrcodeexplaintotp'] = 'Descargue una aplicación de autenticación en su teléfono. Dos aplicaciones que funcionan bien son <strong>FreeOTP</strong> y <strong>Google Authenticator</strong>, sin embargo, cualquier otra aplicación que soporte TOTP debería funcionar.<br /><br />
Inicie la aplicación en su teléfono y añada una nueva entrada para este servicio. Cuando se le solicite, escanee el código QR mostrado abajo para configurar su aplicación móvil.';
$kolab2FaLabels['verifycode'] = 'Verificar código';
$kolab2FaLabels['verifycodeexplaintotp'] = 'Una vez que haya escaneado el código QR, ingrese el código de verificación de 6 dígitos generado por la aplicación de autenticación.';
$kolab2FaLabels['created'] = 'Creado';
$kolab2FaLabels['remove'] = 'Eliminar';
$kolab2FaLabels['highsecurityrequired'] = 'Seguridad elevada - Verificación requerida';
$kolab2FaLabels['highsecuritydialog'] = 'El modo de seguridad elevada ayuda a proteger su cuenta de amenazas de seguridad, como robo de sesión o alguien ingresando a su cuenta mientras usted no se encuentra presente. Para entrar al modo de seguridad elevada, confirme sus credenciales con el siguiente factor de autenticación adicional:';
$kolab2FaLabels['enterhighsecurity'] = 'Confirmar';
$kolab2FaLabels['authremoveconfirm'] = '¿Confirma que desea eliminar este factor de autenticación de su cuenta?';
$kolab2FaLabels['factorsavesuccess'] = 'Factor de autenticación guardado correctamente';
$kolab2FaLabels['factorremovesuccess'] = 'Factor de autenticación eliminado correctamente';
$kolab2FaLabels['codeverificationfailed'] = 'El código de verificación $method falló';
$kolab2FaLabels['continue'] = 'Continuar';