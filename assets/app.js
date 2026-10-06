import './bootstrap.js';
import Chart from '/';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
// Exemple test
document.addEventListener("DOMContentLoaded", () => {
    const ctx = document.getElementById('myChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['A', 'B', 'C', 'D'],
                datasets: [{
                    label: 'Exemple',
                    data: [12, 19, 3, 5],
                    backgroundColor: ['#3f1a01','#8B4513','#CD853F','#DEB887']
                }]
            }
        });
    }
});