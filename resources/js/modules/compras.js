document.addEventListener('alpine:init', () => {

    Alpine.data('purchaseForm', () => ({

    purchaseSaving: false,
    editingPurchase: window.location.pathname.includes('/edit'),

    purchaseDate: window.purchaseEdit?.date || new Date().toISOString().slice(0, 10),
    supplierInvoiceNumber: '',
    paymentType: window.purchaseEdit?.payment || 'cash',
    dueDate: '',
    purchaseNotes: window.purchaseEdit?.notes || '',
    
    supplierSearch: '',
    supplierResults: [],
    supplierLoading: false,
    supplierOpen: false,
    selectedSupplier: window.purchaseEdit?.supplier || null,
    supplierModalOpen: false,

        newSupplier: {
        name: '',
        commercial_name: '',
        identification: '',
        phone: '',
        mobile: '',
        email: '',
    },

    productSearch: '',
        productResults: [],
        productLoading: false,
        productOpen: false,
productModalOpen: false,

newProduct: {
    category_id: '',
    brand_id: '',
    unit_id: '',
    name: '',
    internal_code: '',
    barcode: '',
    cost: 0,
    sale_price: 0,
    tax_rate: 13,
},

items: window.purchaseEdit?.items || [],

        async searchSuppliers() {

            const search = this.supplierSearch.trim();

            if (!search) {
                this.supplierResults = [];
                this.supplierOpen = false;
                return;
            }

            this.supplierLoading = true;

            try {

                const response = await fetch(
                    `/proveedores-buscar?search=${encodeURIComponent(search)}`,
                    {
                        headers: {
                            'Accept': 'application/json'
                        }
                    }
                );
                

                if (!response.ok) {
                    throw new Error('No se pudo buscar proveedores.');
                }

                this.supplierResults = await response.json();
                this.supplierOpen = true;

            } catch (error) {

                console.error(error);
                this.supplierResults = [];

            } finally {

                this.supplierLoading = false;
            }
        },

        selectSupplier(supplier) {

            this.selectedSupplier = supplier;

            this.supplierSearch =
                supplier.commercial_name || supplier.name;

            this.supplierOpen = false;
        },

        clearSupplier() {

            this.selectedSupplier = null;
            this.supplierSearch = '';
            this.supplierResults = [];
            this.supplierOpen = false;
        },

        async saveSupplier() {

    if (!this.newSupplier.name.trim()) {
        alert('Debe ingresar el nombre o razón social del proveedor.');
        return;
    }

    try {

        const response = await fetch('/proveedores', {
            method: 'POST',

            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document
                    .querySelector('meta[name="csrf-token"]')
                    .getAttribute('content')
            },

            body: JSON.stringify({
                supplier_type: 'company',
                identification_type: null,
                identification: this.newSupplier.identification || null,
                name: this.newSupplier.name,
                commercial_name: this.newSupplier.commercial_name || null,
                contact_name: null,
                phone: this.newSupplier.phone || null,
                mobile: this.newSupplier.mobile || null,
                email: this.newSupplier.email || null,
                country_id: null,
                province_id: null,
                canton_id: null,
                district_id: null,
                address: null,
                credit_days: 0,
                credit_limit: 0,
                notes: null,
                is_active: true
            })
        });

        const text = await response.text();

console.log(text);

const data = JSON.parse(text);

        if (!response.ok) {

            if (data.errors) {
                const firstError = Object.values(data.errors)[0];

                alert(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : firstError
                );

                return;
            }

            throw new Error(
                data.message || 'No se pudo guardar el proveedor.'
            );
        }

        this.selectedSupplier = data;

        this.supplierSearch =
            data.commercial_name || data.name;

        this.supplierResults = [];
        this.supplierOpen = false;
        this.supplierModalOpen = false;

        this.newSupplier = {
            name: '',
            commercial_name: '',
            identification: '',
            phone: '',
            mobile: '',
            email: '',
        };

    } catch (error) {

        console.error(error);

        alert(
            'Ocurrió un error al guardar el proveedor.'
        );
    }
},

        async searchProducts() {

            const search = this.productSearch.trim();

            if (!search) {
                this.productResults = [];
                this.productOpen = false;
                return;
            }

            this.productLoading = true;

            try {

                const response = await fetch(
                    `/compras-buscar-productos?q=${encodeURIComponent(search)}`,
                    {
                        headers: {
                            'Accept': 'application/json'
                        }
                    }
                );

                if (!response.ok) {
                    throw new Error('No se pudo buscar productos.');
                }

                this.productResults = await response.json();
                this.productOpen = true;

            } catch (error) {

                console.error(error);
                this.productResults = [];

            } finally {

                this.productLoading = false;
            }
        },

        addProduct(product) {

            const existing = this.items.find(
                item => item.id === product.id
            );

            if (existing) {

                existing.quantity =
                    Number(existing.quantity || 0) + 1;

            } else {

                this.items.push({
                    ...product,
                    quantity: 1,
                    unit_cost: Number(product.cost || 0),
                    new_sale_price: ''
                });
            }

            this.productSearch = '';
            this.productResults = [];
            this.productOpen = false;
        },

        async saveProduct() {


    if (!this.newProduct.name.trim()) {
        alert('Debe ingresar el nombre del producto.');
        return;
    }

    if (!this.newProduct.internal_code.trim()) {
        alert('Debe ingresar el código interno.');
        return;
    }

    if (!this.newProduct.category_id) {
        alert('Debe seleccionar una categoría.');
        return;
    }

    if (!this.newProduct.unit_id) {
        alert('Debe seleccionar una unidad.');
        return;
    }

    try {

        const response = await fetch('/productos', {
            method: 'POST',

            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document
                    .querySelector('meta[name="csrf-token"]')
                    .getAttribute('content')
            },

            body: JSON.stringify({
                category_id: this.newProduct.category_id,
                brand_id: this.newProduct.brand_id || null,
                unit_id: this.newProduct.unit_id,
                name: this.newProduct.name,
                internal_code: this.newProduct.internal_code,
                barcode: this.newProduct.barcode || null,

                product_type: 'product',

                cabys_code: null,
                short_description: null,
                description: null,

                cost: Number(this.newProduct.cost || 0),
                sale_price: Number(this.newProduct.sale_price || 0),

                wholesale_price: null,
                special_price: null,

                stock: 0,
                track_inventory: true,
                minimum_stock: 0,
                maximum_stock: 0,
                allow_negative_stock: false,

                tax_rate: Number(this.newProduct.tax_rate || 0),

                is_active: true
            })
        });

        const responseText = await response.text();

alert(responseText);

const data = JSON.parse(responseText);

        if (!response.ok) {

            if (data.errors) {
                const firstError = Object.values(data.errors)[0];

                alert(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : firstError
                );

                return;
            }

            throw new Error(
                data.message || 'No se pudo guardar el producto.'
            );
        }

        this.addProduct(data);

        this.productModalOpen = false;

        this.newProduct = {
            category_id: '',
            brand_id: '',
            unit_id: '',
            name: '',
            internal_code: '',
            barcode: '',
            cost: 0,
            sale_price: 0,
            tax_rate: 13,
        };

    } catch (error) {

        console.error(error);

        alert('Ocurrió un error al guardar el producto.');
    }
},

        removeProduct(index) {
            this.items.splice(index, 1);
        },

        lineTotal(item) {

            const quantity = Number(item.quantity || 0);
            const cost = Number(item.unit_cost || 0);
            const taxRate = Number(item.tax_rate || 0);

            const subtotal = quantity * cost;
            const tax = subtotal * (taxRate / 100);

            return subtotal + tax;
        },

        grandTotal() {

            return this.items.reduce(
                (total, item) => total + this.lineTotal(item),
                0
            );
        },


async savePurchase() {

    if (!this.selectedSupplier) {
        alert('Debe seleccionar un proveedor.');
        return;
    }

    if (!this.purchaseDate) {
        alert('Debe indicar la fecha de compra.');
        return;
    }

    if (this.items.length === 0) {
        alert('Debe agregar al menos un producto.');
        return;
    }

    if (this.paymentType === 'credit' && !this.dueDate) {
        alert('Debe indicar la fecha de vencimiento.');
        return;
    }

    this.purchaseSaving = true;

    try {

        const isEditing = window.location.pathname.includes('/edit');

const url = isEditing
    ? window.location.pathname.replace(/\/edit$/, '')
    : '/compras';

const method = isEditing
    ? 'PUT'
    : 'POST';

console.log('URL QUE ENVIA:', url);

console.log('ITEMS ENVIADOS:', this.items);

const response = await fetch(url, {
    method: method,

            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document
                    .querySelector('meta[name="csrf-token"]')
                    .getAttribute('content')
            },

            body: JSON.stringify({
                supplier_id: this.selectedSupplier.id,
                supplier_invoice_number:
                    this.supplierInvoiceNumber || null,

                purchase_date: this.purchaseDate,
                payment_type: this.paymentType,

                due_date:
                    this.paymentType === 'credit'
                        ? this.dueDate
                        : null,

                notes: this.purchaseNotes || null,

                items: this.items.map(item => ({
                    product_id: item.product_id ?? item.id,
                    quantity: Number(item.quantity || 0),
                    unit_cost: Number(item.unit_cost || 0),

                    new_sale_price:
                        item.new_sale_price !== '' &&
                        item.new_sale_price !== null
                            ? Number(item.new_sale_price)
                            : null
                }))
            })
        });

        const responseText = await response.text();

console.log('RESPUESTA SERVIDOR:', responseText);

const data = JSON.parse(responseText);

        if (!response.ok) {

            if (data.errors) {

                const firstError =
                    Object.values(data.errors)[0];

                alert(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : firstError
                );

                return;
            }

            throw new Error(
                data.message || 'No se pudo guardar la compra.'
            );
        }

alert(data.message);

window.location.href = data.redirect;

    } catch (error) {

        console.error(error);

        alert(
            error.message ||
            'Ocurrió un error al guardar la compra.'
        );

    } finally {

        this.purchaseSaving = false;
    }
},

        money(value) {

            return new Intl.NumberFormat('es-CR', {
                style: 'currency',
                currency: 'CRC',
                minimumFractionDigits: 2
            }).format(Number(value || 0));
        },

        formatNumber(value) {

            return new Intl.NumberFormat('es-CR', {
                maximumFractionDigits: 4
            }).format(Number(value || 0));
        }

    }));

});