// assets/js/carrito.js - CORREGIDO
// JavaScript para manejo del carrito de compras

// Función para agregar producto al carrito
async function agregarAlCarrito(idProducto, cantidad = 1) {
    try {
        // Obtener el botón que disparó el evento
        const btn = event ? event.target : document.querySelector(`button[onclick*="${idProducto}"]`);
        if (!btn) {
            throw new Error('No se pudo encontrar el botón');
        }
        
        // Mostrar indicador de carga en el botón
        const textoOriginal = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Agregando...';
        btn.disabled = true;
        
        const formData = new FormData();
        formData.append('action', 'agregar');
        formData.append('id_producto', idProducto);
        formData.append('cantidad', cantidad);
        
        const response = await fetch('api/carrito.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Error en la respuesta del servidor');
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Mostrar mensaje de éxito
            mostrarNotificacion(data.message, 'success');
            
            // Actualizar contador del carrito
            actualizarContadorCarrito();
            
            // Efecto visual en el botón
            btn.innerHTML = '<i class="fas fa-check"></i> ¡Agregado!';
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            
            setTimeout(() => {
                btn.innerHTML = textoOriginal;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
                btn.disabled = false;
            }, 2000);
            
        } else {
            throw new Error(data.message || 'Error desconocido');
        }
        
    } catch (error) {
        console.error('Error:', error);
        mostrarNotificacion('Error: ' + error.message, 'error');
        
        // Restaurar botón en caso de error
        if (event && event.target) {
            const btn = event.target;
            btn.innerHTML = '<i class="fas fa-cart-plus"></i> Agregar al Carrito';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
            btn.disabled = false;
        }
    }
}

// Función para actualizar contador del carrito
async function actualizarContadorCarrito() {
    try {
        const response = await fetch('api/carrito.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=contar'
        });
        
        if (!response.ok) {
            throw new Error('Error en la respuesta del servidor');
        }
        
        const data = await response.json();
        
        if (data.success) {
            const contador = data.data || 0;
            const badgeElement = document.getElementById('cart-count');
            
            if (badgeElement) {
                badgeElement.textContent = contador;
                
                // Animar el contador si cambió
                if (contador > 0) {
                    badgeElement.classList.remove('d-none');
                    // Animación de pulso
                    badgeElement.style.transform = 'scale(1.3)';
                    badgeElement.style.transition = 'transform 0.2s ease';
                    setTimeout(() => {
                        badgeElement.style.transform = 'scale(1)';
                    }, 200);
                } else {
                    badgeElement.classList.add('d-none');
                }
            }
        }
        
    } catch (error) {
        console.error('Error al actualizar contador:', error);
        // No mostrar notificación para errores de contador, solo loguear
    }
}

// Función para mostrar notificaciones mejorada
function mostrarNotificacion(mensaje, tipo = 'info') {
    // Remover notificaciones existentes
    const notificacionesExistentes = document.querySelectorAll('.notificacion-carrito');
    notificacionesExistentes.forEach(n => n.remove());
    
    // Crear elemento de notificación
    const notificacion = document.createElement('div');
    notificacion.className = `alert alert-${tipo === 'success' ? 'success' : tipo === 'error' ? 'danger' : 'info'} alert-dismissible fade show position-fixed notificacion-carrito`;
    notificacion.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        border: none;
        border-radius: 8px;
    `;
    
    const iconos = {
        'success': 'check-circle',
        'error': 'exclamation-triangle',
        'info': 'info-circle'
    };
    
    notificacion.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas fa-${iconos[tipo] || 'info-circle'} me-2"></i>
            <span>${mensaje}</span>
            <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
        </div>
    `;
    
    document.body.appendChild(notificacion);
    
    // Auto-eliminar después de 5 segundos
    setTimeout(() => {
        if (notificacion.parentNode) {
            notificacion.classList.remove('show');
            setTimeout(() => notificacion.remove(), 150);
        }
    }, 5000);
}

// Función para ver carrito rápido (modal)
async function verCarritoRapido() {
    try {
        const response = await fetch('api/carrito.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=obtener'
        });
        
        if (!response.ok) {
            throw new Error('Error en la respuesta del servidor');
        }
        
        const data = await response.json();
        
        if (data.success) {
            mostrarModalCarrito(data.data);
        } else {
            throw new Error(data.message || 'Error al cargar carrito');
        }
        
    } catch (error) {
        console.error('Error:', error);
        mostrarNotificacion('Error al cargar carrito: ' + error.message, 'error');
    }
}

// Función para mostrar modal del carrito
function mostrarModalCarrito(carritoData) {
    // Crear modal si no existe
    let modal = document.getElementById('cart-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'cart-modal';
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-shopping-cart"></i> Tu Carrito
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="cart-modal-body">
                        <!-- Contenido dinámico -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Seguir Comprando</button>
                        <a href="carrito.php" class="btn btn-primary">Ver Carrito Completo</a>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Llenar contenido del modal
    const modalBody = document.getElementById('cart-modal-body');
    
    if (!carritoData || carritoData.items.length === 0) {
        modalBody.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                <p class="h5">Tu carrito está vacío</p>
                <p class="text-muted">¡Agrega algunos productos y vuelve aquí!</p>
            </div>
        `;
    } else {
        let itemsHtml = '';
        carritoData.items.forEach(item => {
            itemsHtml += `
                <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
                    <div class="flex-grow-1">
                        <h6 class="mb-1">${item.nombre}</h6>
                        <small class="text-muted">Cantidad: ${item.cantidad} | $${item.precio.toFixed(2)} c/u</small>
                    </div>
                    <div class="text-end">
                        <strong class="text-success">$${item.subtotal.toFixed(2)}</strong>
                    </div>
                </div>
            `;
        });
        
        modalBody.innerHTML = `
            <div style="max-height: 400px; overflow-y: auto;">
                ${itemsHtml}
            </div>
            <div class="d-flex justify-content-between mt-3 pt-3 border-top">
                <strong>Total:</strong>
                <strong class="text-success h5">$${carritoData.total.toFixed(2)}</strong>
            </div>
        `;
    }
    
    // Mostrar modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}

// Función para manejar compra rápida (agregar y ir al carrito)
async function compraRapida(idProducto, cantidad = 1) {
    try {
        // Mostrar loading
        mostrarNotificacion('Agregando producto...', 'info');
        
        await agregarAlCarrito(idProducto, cantidad);
        
        // Esperar un poco para que se procese
        setTimeout(() => {
            window.location.href = 'carrito.php';
        }, 1000);
        
    } catch (error) {
        console.error('Error en compra rápida:', error);
        mostrarNotificacion('Error en compra rápida: ' + error.message, 'error');
    }
}

// Inicializar cuando carga la página
document.addEventListener('DOMContentLoaded', function() {
    // Actualizar contador del carrito
    actualizarContadorCarrito();
    
    // Agregar event listener al enlace del carrito para mostrar vista rápida
    const cartLink = document.querySelector('a[href="carrito.php"]');
    if (cartLink && !window.location.pathname.includes('carrito.php')) {
        cartLink.addEventListener('click', function(e) {
            e.preventDefault();
            verCarritoRapido();
        });
    }
    
    // Actualizar contador cada 30 segundos (por si hay cambios en otras pestañas)
    setInterval(actualizarContadorCarrito, 30000);
    
    // Manejar errores globales de JavaScript
    window.addEventListener('error', function(e) {
        console.error('Error global:', e.error);
    });
    
    // Manejar errores de promesas no capturadas
    window.addEventListener('unhandledrejection', function(e) {
        console.error('Promise rejection no manejada:', e.reason);
    });
});

// Función auxiliar para debug
function debugCarrito() {
    console.log('Estado del carrito en sesión:', sessionStorage.getItem('carrito_debug'));
    actualizarContadorCarrito().then(() => {
        console.log('Contador actualizado');
    }).catch(e => {
        console.error('Error actualizando contador:', e);
    });
}
// Función para recomprar pedido (agregar todos los productos al carrito)
async function recomprarPedido(idPedido) {
    try {
        // Obtener el botón que disparó el evento
        const btn = event ? event.target : document.querySelector(`button[onclick*="recomprarPedido(${idPedido})"]`);
        
        if (!confirm('¿Quieres agregar todos los productos de este pedido a tu carrito actual?')) {
            return;
        }
        
        // Mostrar loading en el botón
        if (btn) {
            const textoOriginal = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recomprando...';
            btn.disabled = true;
            
            // Restaurar función para casos de error
            window.restaurarBotonRecompra = function() {
                btn.innerHTML = textoOriginal;
                btn.disabled = false;
            };
        }
        
        const formData = new FormData();
        formData.append('action', 'recomprar');
        formData.append('id_pedido', idPedido);
        
        const response = await fetch('api/carrito.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Error en la respuesta del servidor');
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Mostrar mensaje de éxito
            mostrarNotificacion(data.message, 'success');
            
            // Actualizar contador del carrito
            actualizarContadorCarrito();
            
            // Efecto visual en el botón
            if (btn) {
                btn.innerHTML = '<i class="fas fa-check"></i> ¡Recomprado!';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-shopping-cart"></i> Recomprar';
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                    btn.disabled = false;
                }, 3000);
            }
            
            // Preguntar si quiere ir al carrito
            setTimeout(() => {
                if (confirm('Productos agregados correctamente. ¿Quieres ir al carrito para revisar tu compra?')) {
                    window.location.href = 'carrito.php';
                }
            }, 1500);
            
        } else {
            throw new Error(data.message || 'Error desconocido al recomprar');
        }
        
    } catch (error) {
        console.error('Error al recomprar:', error);
        mostrarNotificacion('Error al recomprar: ' + error.message, 'error');
        
        // Restaurar botón en caso de error
        if (window.restaurarBotonRecompra) {
            window.restaurarBotonRecompra();
        }
    }
}