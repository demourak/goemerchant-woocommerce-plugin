/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

function disableUnusedFields() {

    var newCardElements = [
        document.getElementById("goe-card-number"), 
        document.getElementById("goe-card-expiry"), 
        document.getElementById("goe-card-cvc"),
        document.getElementById("goe-save-card")
    ];
    
    var existingCardMenu = document.getElementById("goe-selected-card-id");
    
    var existingCardElements = [
        document.getElementById("goe-card-cvc-saved"),
        existingCardMenu
    ];
    
    
    if (document.getElementById("goe-use-existing-card-id").checked === true) {
        newCardElements.forEach(function(element) {
            element.disabled = true;
        });
        
        existingCardElements.forEach(function(element) {
            element.disabled = false;
        });
        existingCardMenu.style.color = 'black';
    } 
    else {
        newCardElements.forEach(function(element) {
            element.disabled = false;
        });
        
        existingCardElements.forEach(function(element) {
            element.disabled = true;
        });
        existingCardMenu.style.color = 'gray';
    }
}