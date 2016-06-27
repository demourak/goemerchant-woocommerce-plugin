<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

function check($string, $subject = 'PHP Debug', $to = 'kevin.demoura@goemerchant.com') {
    mail($to, $subject, $string);
}