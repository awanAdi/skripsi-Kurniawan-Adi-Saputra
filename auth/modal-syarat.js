const btnSyarat = document.getElementById('btnSyarat');
const modal = document.getElementById('modalSyarat');
const closeModal = document.getElementById('closeModal');

btnSyarat.onclick = () => {
    modal.style.display = 'flex';
};

closeModal.onclick = () => {
    modal.style.display = 'none';
};

window.onclick = (e) => {
    if (e.target === modal) {
        modal.style.display = 'none';
    }
};
