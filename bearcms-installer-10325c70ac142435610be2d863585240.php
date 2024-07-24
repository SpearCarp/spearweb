<?php
/*
 * Bear CMS Standalone Installer
 * https://bearcms.com/
 * Copyright (c) Amplilabs Ltd.
 * Free to use under the MIT license.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('default_charset', 'UTF-8');

ignore_user_abort(true);
set_time_limit(0);

function handleError($errno, $errstr)
{
    renderError('PHP error: ' . $errstr . '! (1001)');
}

function handleFatalError()
{
    $lastErrorData = error_get_last();
    if ($lastErrorData !== null) {
        renderError('PHP Fatal error: ' . (isset($lastErrorData['message']) ? $lastErrorData['message'] : 'undefined') . '! (1000)');
    }
}

function renderError($data)
{
    if (ob_get_length() > 0) {
        ob_clean();
    }
    echo $data;
    exit;
}

ob_start();

register_shutdown_function('handleFatalError');
set_error_handler('handleError', E_ALL | E_STRICT);

$publicDir = str_replace('\\', '/', __DIR__) . '/';
$bearCMSDir = str_replace('\\', '/', dirname(__DIR__)) . '/bearcms/';

$isInstalled = function() use ($bearCMSDir) {
    return is_file($bearCMSDir . 'core/config.php');
};

if (isset($_POST['install'])) {
    if ($isInstalled()) {
        renderError('A configuration file (' . $bearCMSDir . 'core/config.php) from a previous Bear CMS installation is found! Please remove it before running the installer again.');
    }

    if (version_compare(PHP_VERSION, '7.1.0', '<')) {
        renderError('The Bear CMS client requires PHP version 7.1.0 or newer! Current version is ' . PHP_VERSION . '. (1002)');
    }

    $secretKey = isset($_POST['secretKey']) ? trim((string) $_POST['secretKey']) : '';

    if (!isset($secretKey[0])) {
        renderError('The secret key is required!');
    }

    if (!function_exists('curl_init')) {
        renderError('The PHP library cURL is required for installing the Bear CMS client! (1006)');
    }

    if (isset($_SERVER['SCRIPT_NAME']) && pathinfo($_SERVER['SCRIPT_NAME'], PATHINFO_BASENAME) === 'index.php') {
        renderError('The installer file cannot be named index.php! (1003)');
    }

    $makeDir = function($dir) {
        if (is_dir($dir)) {
            if (!is_writable($dir)) {
                renderError('The directory ' . $dir . ' is not writable! (1004)');
            }
        } else {
            if (!mkdir($dir, 0777, true)) {
                renderError('Cannot create directory ' . $dir . '! (1005)');
            }
        }
    };

    $makeFile = function($filename, $content, $ignoreIfExists = false) use ($makeDir) {
        if ($ignoreIfExists && is_file($filename)) {
            return;
        }
        $makeDir(pathinfo($filename, PATHINFO_DIRNAME));
        file_put_contents($filename, $content);
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'php') {
            clearstatcache(true, $filename);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($filename);
            }
        }
    };

    $makeFile($publicDir . 'index.php', base64_decode('PD9waHAKCi8qCiAqIFRoaXMgZmlsZSBpcyBnZW5lcmF0ZWQgYnkgdGhlIEJlYXIgQ01TIFN0YW5kYWxvbmUgSW5zdGFsbGVyLgogKiBEbyBub3QgZWRpdCB0aGlzIGZpbGUsIGJlY2F1c2UgYWxsIHRoZSBjaGFuZ2VzIHdpbGwgYmUgbG9zdCBhZnRlciBydW5uaW5nIHRoZSB1cGRhdGUgbWFuYWdlci4KICovCgokY29yZUluZGV4ID0gX19ESVJfXyAuICcvLi4vYmVhcmNtcy9jb3JlL2luZGV4LnBocCc7CmlmIChpc19maWxlKCRjb3JlSW5kZXgpKSB7CiAgICByZXF1aXJlICRjb3JlSW5kZXg7Cn0gZWxzZSB7CiAgICBlY2hvICdDYW5ub3QgZmluZCB0aGUgY29yZSBpbmRleCBmaWxlISBZb3UgY2FuIGZpeCB0aGlzIGJ5IHJ1bm5pbmcgdGhlIGluc3RhbGxlciBhZ2Fpbi4nOwogICAgZXhpdDsKfQ=='));
    $makeFile($publicDir . '.htaccess', base64_decode('PElmTW9kdWxlIG1vZF9yZXdyaXRlLmM+CgpSZXdyaXRlRW5naW5lIE9uCgpSZXdyaXRlQ29uZCAle1JFUVVFU1RfRklMRU5BTUV9ICEtZApSZXdyaXRlQ29uZCAle1JFUVVFU1RfRklMRU5BTUV9ICEtZgpSZXdyaXRlUnVsZSBeIGluZGV4LnBocCBbTF0KCjwvSWZNb2R1bGU+'), true);

    $makeDir($bearCMSDir . '/data');
    $makeDir($bearCMSDir . '/logs');

    $makeFile($bearCMSDir . 'core/config.php', '<?php
return [
    \'appSecretKey\' => \'' . addslashes($secretKey) . '\'
];
');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://bearcms.com/api/client/manager');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    if (isset($error[0])) {
        throw new \Exception('Request curl error: ' . $error . '! (1027)');
    }
    if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
        throw new \Exception('');
    }

    $makeFile($bearCMSDir . 'core/manager.php', $response);

    $manager = require $bearCMSDir . 'core/manager.php';
    try {
        $statusLog = [];
        if (!$manager->update($statusLog)) {
            unlink($bearCMSDir . 'core/config.php');
            $statusLog = implode("\n", $statusLog);
            if (strpos($statusLog, 'Invalid appSecretKey!') !== false) {
                renderError('The secret key provided is not valid! You can generate a new one from your bearcms.com account!');
            } else {
                renderError('Cannot download and install requirements! Please check the bearcms/core/update.log file!');
            }
        }
    } catch (\Exception $e) {
        renderError($e->getMessage());
    }

    echo '1';
    exit;
}
?><!DOCTYPE html>
<html>
    <head>
        <title>Bear CMS Installer</title>
        <meta charset="utf-8"/>
        <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=0,minimal-ui"/>
        <style>
            *,*:before,*:after{margin:0;padding:0;-moz-box-sizing:border-box;-webkit-box-sizing:border-box;box-sizing:border-box;outline:none;-webkit-tap-highlight-color:rgba(0,0,0,0);}
            html,body{font-size:14px;line-height:27px;}
            body{background-color:#fafafa;color:#111;font-family:Helvetica,Arial,sans-serif;padding:45px 15px;word-break:break-word;}
            .lg{width:130px;height:40px;margin-bottom:30px;background-image:url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzOTguNDA3IiBoZWlnaHQ9IjQ2Ljk3NCIgdmlld0JveD0iMCAwIDM5OC40MDY1MSA0Ni45NzMyMjciPjxwYXRoIGZpbGw9IiMwMDAiIGQ9Ik02Mi4xNy4yN2MxLjA0LS4wMiAyLjEzLjggMi41NSAyLjE0LjE1LjU2LjEgMS4zLjQzIDEuNiAyLjAyIDEuODggNS4zNCAxLjY0IDYuMDQgNC45LjEyLjc1IDIgMi4zIDIuOTIgMy4yLjguNzcgMiAyLjEzIDEuNzYgMi44Ni0uNSAxLjY2LTEuMTYgMy42NS0zLjY1IDMuNi0zLjY0LS4wNi03LjMtLjA0LTEwLjk0IDAtNC42Ny4wNC03LjQ1IDIuODItNy41IDcuNTMtLjA2IDMuOC4wNiA3LjYzLS4wNCAxMS40Ni0uMDggMy4wMiAxLjI1IDQuNjggNC4xOCA1LjM2LjkzLjI0IDEuNSAxLjEuODQgMS45LS44IDEtNC4zIDEtNC40IDEtMi44LjMzLTYuNS0uNy04Ljc3LTYuNC0xLjMgMS43LTIuMiAyLjU2LTMuNCAyLjk0LS43LjIzLTQuMTcgMS4xLTQuMy4zMi0uMjUtMS40NSAzLjktNS4wNCA0LjA3LTYuNS4zLTIuODUtMi4xNy0zLjktNS4wNC00LjYyLTIuOS0uNzMtNiAuNTgtNy4zIDEuOTYtMS44IDEuOS0xLjcgNy43Ny0uNzYgOC4yNi41Mi4yNSAxLjQ3LjggMS41IDEuNi4wMi42LS43NSAxLjUtMS4yIDEuNS0yLjUuMTYtNS4wMi4yNS03LjQ3LS4wNi0uNjUtLjA4LTEuNi0xLjY2LTEuNi0yLjU0LjA0LTIuODgtNS41LTcuOS02LjQtNi42LTEuNTIgMi4xNS02LjA0IDMuMjItNS41IDYuMDMuMzQgMS44IDMuOS42IDQuMjUgMiAuNzYgMy4yLTYuOCAyLjEtOS44NyAxLjctMi41OC0uMzMtMy42My0xLjgzLTEuMzItNi45QzQuMDYgMzMuNCA0LjQ4IDI4LjEgNCAyMi4zNCAzLjA1IDkuODggMTEuNSAxLjIzIDI0LjA1IDEuNTZjMTAuOS4yNSAyMS45LS4yIDMyLjcgMS4zIDYuMS44MyAyLjcyLjEgMy43Ny0xLjU4LjQyLS42OCAxLjAzLTEgMS42NS0xek0xMDUuMDMuNjZoMTQuMjJxOS43MiAwIDE0LjEgMi43OCA0LjQgMi43NSA0LjQgOC43OCAwIDQuMS0xLjkzIDYuNzItMS45IDIuNjItNS4xIDMuMTZ2LjNxNC4zNS45OCA2LjI1IDMuNjMgMS45NCAyLjY2IDEuOTQgNy4wNyAwIDYuMjUtNC41IDkuNzV0LTEyLjI1IDMuNWgtMTcuMXptOS43IDE4LjFoNS42MnEzLjkzIDAgNS42OC0xLjIzIDEuOC0xLjIyIDEuOC00LjAzIDAtMi42Mi0xLjk1LTMuNzUtMS45LTEuMTYtNi4wNi0xLjE2aC01LjF6bTAgNy42OHYxMS45aDYuM3E0IDAgNS45LTEuNTIgMS45Mi0xLjU0IDEuOTItNC43IDAtNS42OC04LjEzLTUuNjh6TTE2MS43NiAxNy41M3EtMy4wMyAwLTQuNzUgMS45NC0xLjcgMS45LTEuOTQgNS40NGgxMy4zN3EtLjA1LTMuNS0xLjgzLTUuNC0xLjgtMS45NS00LjgtMS45NXptMS4zNCAyOS40NHEtOC40NCAwLTEzLjItNC42NS00Ljc0LTQuNjYtNC43NC0xMy4yIDAtOC43NyA0LjM4LTEzLjU2IDQuNC00LjggMTIuMTUtNC44IDcuNCAwIDExLjUgNC4yIDQuMTMgNC4yMyA0LjEzIDExLjY3djQuNjJIMTU0LjhxLjE3IDQuMDcgMi40IDYuMzUgMi4yNyAyLjI4IDYuMzMgMi4yOCAzLjE2IDAgNS45Ny0uNjYgMi44Mi0uNjUgNS44OC0yLjF2Ny4zOHEtMi41IDEuMjUtNS4zNCAxLjg1LTIuODQuNjItNi45NC42MnpNMjA3LjM2IDQ2LjM1bC0xLjg1LTQuNzVoLS4yMnEtMi40IDMuMDMtNC45NyA0LjIyLTIuNTMgMS4xNS02LjYyIDEuMTUtNS4wNCAwLTcuOTQtMi44Ny0yLjg4LTIuODgtMi44OC04LjIgMC01LjU1IDMuODgtOC4xOCAzLjktMi42NiAxMS43NS0yLjk0bDYuMDUtLjE4di0xLjU0cTAtNS4zLTUuNDQtNS4zLTQuMTcgMC05LjgzIDIuNTJsLTMuMTYtNi40NHE2LjA1LTMuMTUgMTMuNC0zLjE1IDcuMDIgMCAxMC43NyAzLjAzIDMuNzUgMy4wNiAzLjc1IDkuM3YyMy4zem0tMi44Mi0xNi4ybC0zLjcuMTNxLTQuMTQuMTMtNi4xNyAxLjUtMi4wMyAxLjM4LTIuMDMgNC4yIDAgNC4wMiA0LjYyIDQuMDIgMy4zIDAgNS4yOC0xLjkgMi0xLjkgMi01LjA3ek0yNDMuMyAxMC43NXExLjkzIDAgMy4yMi4yOGwtLjcyIDguOTRxLTEuMTYtLjMtMi44Mi0uMy00LjU2IDAtNy4xMiAyLjMzLTIuNTMgMi4zNS0yLjUzIDYuNTZ2MTcuOGgtOS41NFYxMS40aDcuMmwxLjQgNS44OGguNDdxMS42LTIuOTQgNC4zNi00LjcyIDIuOC0xLjggNi4wNC0xLjh6TTI4OS43IDguMDZxLTUuNDYgMC04LjQ2IDQuMTMtMyA0LjA2LTMgMTEuNCAwIDE1LjMgMTEuNDcgMTUuMyA0Ljg0IDAgMTEuNy0yLjR2OC4xMnEtNS42NCAyLjM0LTEyLjU4IDIuMzQtOS45NiAwLTE1LjI0LTYuMDMtNS4yOC02LjA2LTUuMjgtMTcuMzggMC03LjEyIDIuNi0xMi40NyAyLjYtNS40IDcuNDMtOC4yNFEyODMuMiAwIDI4OS43IDBxNi42NiAwIDEzLjM4IDMuMjJsLTMuMTIgNy44N3EtMi41Ni0xLjI0LTUuMTYtMi4xNS0yLjYtLjktNS4xLS45ek0zMzAuNCA0Ni4zNWwtMTEtMzUuODVoLS4yOHEuNiAxMC45NC42IDE0LjZ2MjEuMjVoLTguNjdWLjY1aDEzLjJsMTAuOCAzNC45NWguMkwzNDYuNy42NmgxMy4ydjQ1LjdoLTkuMDNWMjQuN3EwLTEuNTMuMDMtMy41My4wNi0yIC40NC0xMC42NGgtLjI4bC0xMS43OCAzNS44ek0zOTguNCAzMy42NnEwIDYuMi00LjQ2IDkuNzUtNC40NCAzLjYtMTIuMzggMy42LTcuMyAwLTEyLjk0LTIuNzV2LTlxNC42MyAyLjA2IDcuODIgMi45IDMuMi44NSA1Ljg3Ljg1IDMuMiAwIDQuOS0xLjIyIDEuNy0xLjIyIDEuNy0zLjYyIDAtMS4zNS0uNzItMi4zOC0uNzUtMS4wNi0yLjIyLTIuMDMtMS40NC0uOTctNS45LTMuMS00LjItMS45Ni02LjMtMy43Ny0yLjEtMS44Mi0zLjM0LTQuMjItMS4yNC0yLjQtMS4yNC01LjYzIDAtNi4wNSA0LjEtOS41MlEzNzcuMzYgMCAzODQuNjIgMHEzLjU3IDAgNi44Ljg0IDMuMjQuODUgNi43NyAyLjM4bC0zLjE2IDcuNTNxLTMuNjUtMS41LTYuMDYtMi4xLTIuNC0uNi00LjctLjYtMi43NSAwLTQuMiAxLjMtMS41IDEuMjgtMS41IDMuMzQgMCAxLjI1LjYgMi4yMi42Ljk0IDEuODggMS44NCAxLjMuODggNi4xNSAzLjIgNi40IDMuMDUgOC44IDYuMTUgMi40IDMuMDcgMi40IDcuNTR6Ii8+PC9zdmc+');background-repeat:no-repeat;background-position:left center;background-size:contain;}
            .btn{-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;max-width:100%;display:inline-block;text-decoration:none;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;cursor:pointer;border-radius:2px;height:48px;line-height:48px;padding-right:18px;padding-left:18px;color:#fafafa;font-family:Helvetica,Arial,sans-serif;border-width:0;background-color:#333;}
            .btn:hover{background-color:#222;}
            .btn:active{background-color:#111;}
            .sp{border-top:1px solid #e6e6e6;padding-top:30px;margin-top:30px;margin-left:-15px;margin-right:-15px;}
            label{width:100%;max-width:400px;display:inline-block;line-height:18px;padding-bottom:4px;}
            input{width:100%;max-width:400px;line-height:46px;height:48px;padding:0 17px 0 17px;border:1px solid #aaa;border-radius:2px;box-sizing:border-box;}
        </style>
        <script>
            var showError = function (content) {
                var a = document.getElementById("s2");
                a.childNodes[1].innerHTML = content;
                a.style.display = "block";
                document.getElementById("s1").style.display = "none";
            };
            startInstallation = function () {
                document.getElementById("s2").style.display = "none";
                document.getElementById("s3").style.display = "none";
                document.getElementById("s1").style.display = "block";
                var data = {
                    "install": true,
                    "secretKey": document.querySelector('[name="bearcms-secretkey"]').value
                };

                var requestObject = new XMLHttpRequest();
                requestObject.open("POST", "", true);
                requestObject.setRequestHeader("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
                requestObject.addEventListener("load", function () {
                    if (requestObject.status === 200) {
                        var content = requestObject.responseText;
                        if (content === "1") {
                            document.getElementById("s1").style.display = "none";
                            document.getElementById("s4").style.display = "block";
                            document.getElementById("h1").style.display = "none";
                        } else {
                            showError(content);
                        }
                    } else {
                        showError("Client XMLHttpRequest error (3003)");
                    }
                });
                requestObject.addEventListener("error", function () {
                    showError("Client XMLHttpRequest error (3001)");
                });
                requestObject.addEventListener("abort", function () {
                    showError("Client XMLHttpRequest error (3002)");
                });
                var params = [];
                for (var k in data) {
                    params.push(encodeURIComponent(k) + "=" + encodeURIComponent(data[k]));
                }
                requestObject.send(params.join("&"));
            };
        </script>
    </head>
    <body>
        <div style="max-width:400px;margin:0 auto;">
            <div class="lg"></div>

            <div id="h1">
                Let's install the Bear CMS client on this machine. You'll need the secret key from your bearcms.com account.<br><br>

                <label for="bearcms-secretkey">Secret key</label><br>
                <input name="bearcms-secretkey" type="text" value=""/>

                <br><br>
                <div style="color:#888;">A file named index.php will be created in ...</div><?= $publicDir ?><br><br>
                <div style="color:#888;">All other files needed (libraries, configuration, CMS data and logs) will be stored in ...</div> <?= $bearCMSDir ?>
            </div>

            <div>
                <div id="s1" style="padding-top:27px;display:none;">Installing ...</div>
                <div id="s2" style="padding-top:27px;display:none;"><div style="color:red;">An error occured:</div><div></div><div style="padding-top:37px;"><a onclick="startInstallation();" class="btn">Try again</a></div></div>
                <div id="s3" style="padding-top:37px;"><a onclick="startInstallation();" class="btn">Install</a></div>
                <div id="s4" style="display:none;"><div>Congratulations!<br>The Bear CMS client is installed successfully!</div><div style="padding-top:37px;"><a href="index.php/admin/firstrun/" class="btn">Visit the admin panel</a></div></div>
            </div>
        </div>
    </body>
</html>
