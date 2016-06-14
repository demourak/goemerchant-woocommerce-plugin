<!DOCTYPE html>
<!--
To change this license header, choose License Headers in Project Properties.
To change this template file, choose Tools | Templates
and open the template in the editor.
-->
<html>
    <head>
        <meta charset="UTF-8">
        <title></title>
    </head>
    <body>
        <?php
        require_once 'gateway.php';
        require_once 'debug.php';
        
        $rgw = new RestGateway();
        
        test_gw($rgw);
        
        function test_gw($RestGateway) {

            $form = array(
                'merchantKey' => "8854e919-b1a6-475c-803c-121489a35df2",
                'processorId' => "74640",
                'transactionAmount' => "1.00",
                'cardNumber' => "4111111111111111",
                'cardExpMonth' => "05",
                'cardExpYear' => "18",
                'cVV' => "");
            echo "<pre>";
            print_r($form);
            echo "<br>";

            $RestGateway->createSale(
                    $form, 'success', 'errors_and_validation');

            $debug = print_r($RestGateway->result, true);
            check($debug);
            echo "</pre>";
        }

        function success() {
            echo "Success!<br>";
        }
        function errors_and_validation() {
            echo "Problem!<br>";
        }
        ?>
    </body>
</html>
