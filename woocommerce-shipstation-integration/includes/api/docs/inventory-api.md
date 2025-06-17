# ShipStation Inventory API Documentation

## Overview

The ShipStation Inventory API provides endpoints for retrieving and updating inventory stock data for WooCommerce products. This API allows integration with ShipStation to synchronize inventory levels between WooCommerce and ShipStation.

## Authentication

All API requests require authentication. The API uses WordPress REST API authentication methods, specifically requiring the `manage_woocommerce` capability.

## API Endpoints

### Get All Inventory

Retrieves inventory stock data for all products and variations with pagination.

**Endpoint:** `GET /wc-shipstation/v1/inventory`

**Parameters:**

| Parameter | Type    | Required | Default | Description                                      |
|-----------|---------|----------|---------|--------------------------------------------------|
| page      | integer | No       | 1       | Current page of the collection.                  |
| per_page  | integer | No       | 100     | Maximum number of items to be returned in result set. Maximum value is 500. |

**Response:**

```json
{
  "products": [
    {
      "product_id": 123,
      "sku": "PROD-123",
      "name": "Product Name",
      "stock_quantity": 10,
      "stock_status": "instock",
      "manage_stock": true,
      "backorders": "no"
    },
    {
      "product_id": 456,
      "sku": "PROD-456",
      "name": "Another Product",
      "stock_quantity": 5,
      "stock_status": "instock",
      "manage_stock": true,
      "backorders": "no"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 100,
    "total_products": 250,
    "total_pages": 3,
    "has_more": true
  }
}
```

**Status Codes:**

- `200 OK`: Request successful.
- `500 Internal Server Error`: Error retrieving products.

### Get Inventory by Product ID

Retrieves inventory stock data for a specific product by ID.

**Endpoint:** `GET /wc-shipstation/v1/inventory/{product_id}`

**Parameters:**

| Parameter  | Type    | Required | Description                                      |
|------------|---------|----------|--------------------------------------------------|
| product_id | integer | Yes      | ID of the product to retrieve stock data for.    |

**Response:**

```json
{
  "product_id": 123,
  "sku": "PROD-123",
  "name": "Product Name",
  "stock_quantity": 10,
  "stock_status": "instock",
  "manage_stock": true,
  "backorders": "no"
}
```

**Status Codes:**

- `200 OK`: Request successful.
- `404 Not Found`: Product not found.

### Get Inventory by SKU

Retrieves inventory stock data for a specific product by SKU.

**Endpoint:** `GET /wc-shipstation/v1/inventory/sku/{sku}`

**Parameters:**

| Parameter | Type   | Required | Description                                      |
|-----------|--------|----------|--------------------------------------------------|
| sku       | string | Yes      | SKU of the product to retrieve stock data for.   |

**Response:**

```json
{
  "product_id": 123,
  "sku": "PROD-123",
  "name": "Product Name",
  "stock_quantity": 10,
  "stock_status": "instock",
  "manage_stock": true,
  "backorders": "no"
}
```

**Status Codes:**

- `200 OK`: Request successful.
- `404 Not Found`: Product not found.

### Update Inventory

Updates inventory stock levels for specified products by SKU or product ID.

**Endpoint:** `POST /wc-shipstation/v1/inventory/update`

**Request Body:**

```json
[
  {
    "product_id": 123,
    "stock_quantity": 15
  },
  {
    "sku": "PROD-456",
    "stock_quantity": 20
  }
]
```

You can update multiple products in a single request. Each item in the array must include either a `product_id` or `sku` to identify the product, and a `stock_quantity` value to set.

**Response:**

```json
{
  "message": "Inventory updated successfully.",
  "updated": [
    {
      "sku": "PROD-123",
      "product_id": 123,
      "stock": 15
    },
    {
      "sku": "PROD-456",
      "product_id": 456,
      "stock": 20
    }
  ],
  "updated_count": 2,
  "errors": [],
  "error_count": 0
}
```

**Possible Messages:**

- `"Inventory updated successfully."`: All items were updated successfully.
- `"Inventory updated with some errors."`: Some items were updated, but others had errors.
- `"No inventory updated due to errors."`: No items were updated due to errors.
- `"No inventory changes made."`: No changes were made (empty request or no valid items).

**Status Codes:**

- `200 OK`: Request processed (may include errors for individual items).
- `400 Bad Request`: Invalid request format.

## Error Handling

When errors occur with specific items in a batch update, the API will continue processing other items and return information about the errors:

```json
{
  "message": "Inventory updated with some errors.",
  "updated": [
    {
      "sku": "PROD-123",
      "product_id": 123,
      "stock": 15
    }
  ],
  "updated_count": 1,
  "errors": [
    {
      "item": {
        "sku": "INVALID-SKU",
        "stock_quantity": 10
      },
      "message": "Product not found"
    }
  ],
  "error_count": 1
}
```

## Examples

### Example: Get All Inventory (First Page)

**Request:**
```
GET /wc-shipstation/v1/inventory
```

### Example: Get All Inventory (Second Page with 50 Items Per Page)

**Request:**
```
GET /wc-shipstation/v1/inventory?page=2&per_page=50
```

### Example: Get Inventory by Product ID

**Request:**
```
GET /wc-shipstation/v1/inventory/123
```

### Example: Get Inventory by SKU

**Request:**
```
GET /wc-shipstation/v1/inventory/sku/PROD-123
```

### Example: Update Inventory for Multiple Products

**Request:**
```
POST /wc-shipstation/v1/inventory/update
```

**Request Body:**
```json
[
  {
    "product_id": 123,
    "stock_quantity": 15
  },
  {
    "sku": "PROD-456",
    "stock_quantity": 20
  }
]
```

## Notes

- When updating inventory, the API will automatically set `manage_stock` to `true` and update the `stock_status` based on the new stock quantity.
- For products with variations, you need to update each variation individually by its product ID or SKU.
- The maximum number of items per page is 500.
