<?php
/**
 * The main credential creation page.
 *
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPLv3
 */
 
require '../pwpusher_private/config.php'; 
require '../pwpusher_private/database.php';
require '../pwpusher_private/mail.php';
require '../pwpusher_private/security.php';
require '../pwpusher_private/input.php';
require '../pwpusher_private/interface.php';
require '../pwpusher_private/CAS/CAS.php';

//Print the header
print getHeader();

//Print the navbar
print getNavBar();

//Find user arguments, if any.
$arguments = getArguments();
$arguments = checkInput($arguments);  

//If CAS Auth is required, perform setup
if($requireCASAuth) {
    //Uncomment the below line if troubleshooting CAS.
    //The default log is /tmp/phpCAS.log
    //phpCAS::setDebug();
    phpCAS::client(SAML_VERSION_1_1, $casHost, $casPort, $casContext);
    
    //Comment the following line and uncomment the one after if testing and you want to avoid cert errors
    phpCAS::setCasServerCACert($casServerCaCertPath);
    //phpCAS::setNoCasServerValidation();
}

//If the form function argument doesn't exist, print the form for the user.
if ($arguments['func'] == 'none' || $arguments == false) {  

    //Force CAS Authentication in order to load the form
    if($requireCASAuth) { 
        phpCAS::forceAuthentication();
        $_SERVER['PHP_AUTH_USER'] = phpCAS::getUser();
        
        //Grab name attribute, if available
        $attributes = phpCAS::getAttributes();
        if(isset($attributes[$casSamlNameAttribute])){
            $_SERVER['PHP_AUTH_NAME'] = $attributes[$casSamlNameAttribute];
        }
        
    //Fail Apache Authentication if configured but not successful
    } elseif ($requireApacheAuth && empty($_SERVER['PHP_AUTH_USER'])) {
            //This section is a courtesy check; PHP_AUTH_USER can possibly be spoofed 
        //if web auth isn't configured.
        print getError(translate('userNotAuthenticated'));
        print getFooter();
        die();
    }

    //Get form elements
    print getFormElements();

} elseif ($arguments['func'] == 'post') { 

    //Force CAS Authentication in order to post the form
    if($requireCASAuth) { 
        phpCAS::forceAuthentication();
        $_SERVER['PHP_AUTH_USER'] = phpCAS::getUser();
        $attributes = phpCAS::getAttributes();
        if(isset($attributes[$casSamlNameAttribute])){
            $_SERVER['PHP_AUTH_NAME'] = $attributes[$casSamlNameAttribute];
        }
        
    } elseif ($requireApacheAuth && empty($_SERVER['PHP_AUTH_USER'])) {
            //This section is a courtesy check; PHP_AUTH_USER can possibly be spoofed 
        //if web auth isn't configured.
        print getError(translate('userNotAuthenticated'));
        print getFooter();
        die();
    }


    //Else if POST arguments exist and have been verified, process the credential
    //Encrypt the user's credential.
    $encrypted = encryptCred($arguments['cred']); 
    
    //Wipe out the variable with the credential.
    unset($arguments['cred']);  
    
    //Create a unique identifier for the new credential record.
    $id = getUniqueId(); 
    
    //Insert the record into the database.
    insertCred(hashId($id, $salt), $encrypted, $arguments['time'], $arguments['views']);  
    
    //Generate the retrieval URL.
    $url = sprintf(
        "https://%s%s?id=%s", $_SERVER['HTTP_HOST'], $_SERVER['PHP_SELF'], urlencode($id)
    );  

    //Send email if configured and if the email has been filled out
    if ($enableEmail && !empty($arguments['destemail'])) {
        mailURL(
            $url, 
            $arguments['destemail'],
            $arguments['destname'],
            calcExpirationDisplay($arguments['time']), 
            $arguments['views']
        ); 
    }  
    
    //If the URL is configured to be displayed print the URL and associated functions
    if ($displayURL) { 
        print getURL($url); 
    } else { 
        print getSuccess(translate('credentialsCreated')); 
    } 
  
  
} elseif ($arguments['func'] == 'get') {

    //Force CAS Authentication in order to load a credential
    if($requireCASAuth && $protectRetrieve) { 
        phpCAS::forceAuthentication();
        $_SERVER['PHP_AUTH_USER'] = phpCAS::getUser();
        
    //Fail Apache Authentication if configured but not successful
    } elseif ($requireApacheAuth && empty($_SERVER['PHP_AUTH_USER']) && $protectRetrieve) {
            //This section is a courtesy check; PHP_AUTH_USER can possibly be spoofed 
        //if web auth isn't configured.
        print getError(translate('userNotAuthenticated'));
        print getFooter();
        die();
    }

    //If GET arguments exist and have been verified (via hash comparison), retrieve the credential
    $result = retrieveCred(hashId(urldecode($arguments['id']), $salt));   
    
    print('<div class="hero-unit">');
    
    //If no valid entry, deny access and wipe hypothetically existing records
    if (empty($result[0])) {  
        print('<h2>' . translate('expiredLink') . '</h2>');
        //print getError('Link Expired');
      
      
    } else {
        //Otherwise, return the credential.
        //Decrypt the credential
        $cred = decryptCred($result[0]['seccred']);  
        
        //Print credentials
        print getCred($cred);  
        
        print ('<a href="' . $_SERVER['REQUEST_URI'] . '&remove=1">' . 
            '<div class="pagination-centered">' .
            '<button class="btn btn-mini btn-danger">Delete Link</button></a>' .
            '</div>');
        
        //Unset the credential variable
        unset($cred);
    }
    print('</div>');
} elseif ($arguments['func'] == 'remove') {
    //If credential removal is specifically requested
    
    //Force CAS Authentication in order to remove the credential
    if($requireCASAuth && $protectRetrieve) { 
        phpCAS::forceAuthentication();
        $_SERVER['PHP_AUTH_USER'] = phpCAS::getUser();
        
    //Fail Apache Authentication if configured but not successful
    } elseif ($requireApacheAuth && empty($_SERVER['PHP_AUTH_USER']) && $protectRetrieve) {
            //This section is a courtesy check; PHP_AUTH_USER can possibly be spoofed 
        //if web auth isn't configured.
        print getError(translate('userNotAuthenticated'));
        print getFooter();
        die();
    }
    
    //Erase the credential
    eraseCred(hashId($arguments['id'], $salt));
}

//Print the footer
print getFooter();
?>