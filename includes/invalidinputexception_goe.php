<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


/**
 * Description of class-card-number-invalid-exception
 *
 * @author kevin.demoura
 */
class InvalidInputException_goe extends Exception {
    
    public function __construct($message = "Some fields are invalid or blank.", $code = 0, \Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
    
    public function __toString() {
        return $this->message;
    }
    
}
