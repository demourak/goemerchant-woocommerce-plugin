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

        $rgw = new RestGateway();

        test_gw($rgw);

        function test_gw($RestGateway) {

            $form = array(
                'merchantKey' => "8854e919-b1a6-475c-803c-121489a35df2",
                'processorId' => "74638",
                'transactionAmount' => "1.00",
                'cardNumber' => "4111111111111111",
                'cardExpMonth' => "05",
                'cardExpYear' => "18",
                "ownerName" => "NP",
                "ownerStreet" => "NP",
                "ownerCity" => "NP",
                "ownerState" => "NP",
                "ownerZip" => "NP",
                "ownerCountry" => "NP",
                'cVV' => "");
            //echo "<pre>";
            echo "<br>";

            $RestGateway->createSale(
                    $form, 'success', 'errors_and_validation');

            $debug = print_r($RestGateway->result, true);
            check("Result_p: " . print_r($debug, true));
            check("Form_p: " . print_r($form, true));

            //echo "</pre>";
        }

        function success() {
            global $rgw;
            echo "Success!<br/>";
            foreach ($rgw->result as $key => $value){
              echo $key . ' :: ' . $value . "<br/>\n";
            }
        }
        function errors_and_validation() {
            global $rgw;
            echo "Problem!<br/>";
            if ($rgw->result["isError"] == TRUE){
                echo "There was an error processing your request. Gateway Returned: <br/>\n";
                check("Error");
                foreach ($rgw->result["errors"] as $key => $value){
                  echo "<p>" . $value . "</p><br/>\n";
                }
              }
            if ($rgw->result["isValid"] == FALSE){
              echo "There was one or more validation failure(s) processing your request. Gateway Returned: <br/>\n";
              foreach ($rgw->result['validations'] as $key => $value){
                echo $value['key'] . ' :: ' . $value['message'] . "<br/>\n";
              }
            }
        }
        ?>
    </body>
</html>
