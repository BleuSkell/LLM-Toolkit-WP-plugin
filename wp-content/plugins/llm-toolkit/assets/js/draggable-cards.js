document.addEventListener("DOMContentLoaded", function() {
   const cards = document.querySelectorAll(".llm-card");
   const lists = document.querySelectorAll(".card-list");

   let current = "";

   for (const card of cards) {
       card.addEventListener("dragstart", dragStart);
       card.addEventListener("dragend", dragEnd);
   }

   for (const list of lists) {
       list.addEventListener("dragover", dragOver);
       list.addEventListener("dragenter", dragEnter);
       list.addEventListener("dragleave", dragLeave);
       list.addEventListener("drop", dragDrop);
   }

   function dragStart() {
       this.className += " hold";
       current = this;
   }

   function dragEnd() {
       this.className = " llm-card";
   }

   function dragOver(e) {
       e.preventDefault();
   }

   function dragEnter(e) {
       e.preventDefault();
       this.classList.add("hovered");
   }

   function dragLeave() {
       this.classList.remove("hovered");
   }

   function dragDrop() {
       this.classList.remove("hovered");
       this.append(current);
   }
});