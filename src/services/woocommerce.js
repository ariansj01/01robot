const axios = require('axios');
const { woocommerce } = require('../config');

const api = axios.create({
  baseURL: `${woocommerce.url}/wp-json/wc/v3`,
  auth: {
    username: woocommerce.consumerKey,
    password: woocommerce.consumerSecret,
  },
  timeout: 30000,
});

function extractAttribute(product, attrName) {
  if (!product || !product.attributes) return null;
  const attr = product.attributes.find(
    (a) => a.name.toLowerCase().includes(attrName.toLowerCase()) ||
           (a.options && a.options.length && a.options[0] && a.options[0].toString().toLowerCase().includes(attrName.toLowerCase()))
  );
  if (attr) return attr.options ? attr.options.join('، ') : null;

  for (const a of product.attributes) {
    if (a.name && a.name.toLowerCase().includes(attrName.toLowerCase())) {
      return a.options ? a.options.join('، ') : null;
    }
  }
  return null;
}

function getAttributeByName(product, name) {
  if (!product || !product.attributes) return null;
  const attr = product.attributes.find(
    (a) => a.name === name || a.name.toLowerCase() === name.toLowerCase()
  );
  return attr ? (attr.options ? attr.options.join('، ') : null) : null;
}

function getBrand(product) {
  if (product && product.categories) {
    for (const cat of product.categories) {
      if (cat.name) return cat.name;
    }
  }
  const brands = ['ASUS', 'Lenovo', 'HP', 'Dell', 'MSI', 'Acer', 'Apple', 'Samsung', 'Razer', 'Huawei', 'LG'];
  for (const b of brands) {
    if (product.name.includes(b) || product.name.toLowerCase().includes(b.toLowerCase())) {
      return b;
    }
  }
  return getAttributeByName(product, 'برند') || 'نامشخص';
}

function formatProductDetails(product) {
  const isInStock = product.stock_status === 'instock';
  const stockQty = product.stock_quantity !== null ? product.stock_quantity : 'نامشخص';

  const specs = {
    cpu: getAttributeByName(product, 'CPU') || getAttributeByName(product, 'پردازنده') || extractAttribute(product, 'cpu') || extractAttribute(product, 'پردازنده') || 'درج نشده',
    gpu: getAttributeByName(product, 'GPU') || getAttributeByName(product, 'کارت گرافیک') || extractAttribute(product, 'gpu') || extractAttribute(product, 'کارت گرافیک') || 'درج نشده',
    ram: getAttributeByName(product, 'RAM') || getAttributeByName(product, 'حافظه رم') || extractAttribute(product, 'ram') || 'درج نشده',
    ssd: getAttributeByName(product, 'SSD') || getAttributeByName(product, 'حافظه داخلی') || extractAttribute(product, 'ssd') || extractAttribute(product, 'حافظه') || 'درج نشده',
    display: getAttributeByName(product, 'Display') || getAttributeByName(product, 'صفحه نمایش') || extractAttribute(product, 'display') || extractAttribute(product, 'صفحه نمایش') || 'درج نشده',
    weight: getAttributeByName(product, 'Weight') || getAttributeByName(product, 'وزن') || extractAttribute(product, 'weight') || extractAttribute(product, 'وزن') || 'درج نشده',
    battery: getAttributeByName(product, 'Battery') || getAttributeByName(product, 'باتری') || extractAttribute(product, 'battery') || extractAttribute(product, 'باتری') || 'درج نشده',
    color: getAttributeByName(product, 'Color') || getAttributeByName(product, 'رنگ') || extractAttribute(product, 'color') || extractAttribute(product, 'رنگ') || 'درج نشده',
    os: getAttributeByName(product, 'OS') || getAttributeByName(product, 'سیستم عامل') || extractAttribute(product, 'os') || 'درج نشده',
  };

  return {
    id: product.id,
    name: product.name,
    brand: getBrand(product),
    regularPrice: product.regular_price ? Number(product.regular_price).toLocaleString('fa-IR') : 'نامشخص',
    salePrice: product.sale_price ? Number(product.sale_price).toLocaleString('fa-IR') : null,
    currentPrice: product.price ? Number(product.price).toLocaleString('fa-IR') : 'نامشخص',
    currentPriceRaw: Number(product.price) || 0,
    isInStock,
    stockQty,
    status: product.status,
    permalink: product.permalink,
    image: product.images && product.images[0] ? product.images[0].src : null,
    images: product.images ? product.images.map((i) => i.src) : [],
    description: product.short_description ? product.short_description.replace(/<[^>]+>/g, '').trim() : '',
    specs,
    attributes: product.attributes || [],
  };
}

async function getAllProducts(params = {}) {
  try {
    const config = {
      params: {
        per_page: params.per_page || 100,
        page: params.page || 1,
        status: 'publish',
        ...params,
      },
    };
    const response = await api.get('/products', config);
    return response.data.map(formatProductDetails);
  } catch (err) {
    console.error('❌ خطا در دریافت لیست محصولات:', err.message);
    throw err;
  }
}

async function searchProducts(query) {
  try {
    const response = await api.get('/products', {
      params: {
        search: query,
        per_page: 20,
        status: 'publish',
      },
    });
    return response.data
      .filter((p) => p.stock_status === 'instock')
      .map(formatProductDetails);
  } catch (err) {
    console.error('❌ خطا در جستجوی محصولات:', err.message);
    throw err;
  }
}

async function getProductById(id) {
  try {
    const response = await api.get(`/products/${id}`);
    return formatProductDetails(response.data);
  } catch (err) {
    console.error(`❌ خطا در دریافت محصول ${id}:`, err.message);
    throw err;
  }
}

async function getProductsByBrand(brandName) {
  try {
    const allProducts = await getAllProducts({ per_page: 100 });
    return allProducts.filter(
      (p) => p.brand.toLowerCase() === brandName.toLowerCase()
    );
  } catch (err) {
    console.error(`❌ خطا در دریافت محصولات برند ${brandName}:`, err.message);
    throw err;
  }
}

async function getAllBrands() {
  try {
    const allProducts = await getAllProducts({ per_page: 100 });
    const brands = new Set();
    allProducts.forEach((p) => {
      if (p.brand && p.brand !== 'نامشخص') brands.add(p.brand);
    });
    return Array.from(brands).sort();
  } catch (err) {
    console.error('❌ خطا در دریافت لیست برندها:', err.message);
    return [];
  }
}

async function getProductsInRange(minPrice, maxPrice) {
  try {
    const allProducts = await getAllProducts({ per_page: 100 });
    return allProducts.filter((p) => {
      const price = p.currentPriceRaw;
      return p.isInStock && price >= minPrice && price <= maxPrice;
    });
  } catch (err) {
    console.error('❌ خطا در فیلتر محصولات بر اساس قیمت:', err.message);
    throw err;
  }
}

async function testConnection() {
  try {
    const response = await api.get('/products', { params: { per_page: 1 } });
    return {
      success: true,
      productsCount: response.headers['x-wp-total'] || response.data.length,
    };
  } catch (err) {
    return {
      success: false,
      error: err.message,
    };
  }
}

module.exports = {
  getAllProducts,
  searchProducts,
  getProductById,
  getProductsByBrand,
  getAllBrands,
  getProductsInRange,
  testConnection,
  formatProductDetails,
};
