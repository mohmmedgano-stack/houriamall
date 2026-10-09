export type Language = 'ar' | 'en';

export type ProductStatus = 'active' | 'limited' | 'closed' | 'coming_soon';

export interface ProductSpec {
  labelAr: string;
  labelEn: string;
  valueAr: string;
  valueEn: string;
}

export interface Product {
  id: string;
  nameAr: string;
  nameEn: string;
  descriptionAr: string;
  descriptionEn: string;
  category: string;
  categoryEn: string;
  price: number;              // Full product price
  depositAmount: number;      // Merchant-defined deposit (part of the price) paid to reserve
  originalPrice?: number;     // Optional comparison price
  images: string[];
  reservationQuota: number;   // Total pieces available for booking
  reservedCount: number;      // Current reserved slots
  estimatedArrivalAr: string; // e.g. "١٥ نوفمبر ٢٠٢٥"
  estimatedArrivalEn: string; // e.g. "November 15, 2025"
  status: ProductStatus;
  bookingStartsAt?: string;   // Optional ISO time booking opens (countdown until it starts)
  bookingEndsAt?: string;     // Optional ISO time booking closes (countdown until it ends)
  featuresAr: string[];
  featuresEn: string[];
  specs?: ProductSpec[];
  createdAt: string;
}

export type PaymentMethod = 'vodafone_cash' | 'instapay';

export type PaymentStatus = 'pending_verification' | 'deposit_paid' | 'fully_paid' | 'rejected';

export type ReservationStatus = 
  | 'pending'           // Deposit sent, waiting for merchant to verify the transfer
  | 'confirmed'         // Deposit verified & reservation locked
  | 'in_preparation'    // Order is being prepared
  | 'ready_for_delivery'// Ready for pickup at the branch
  | 'completed'         // Picked up at the branch & remaining amount paid
  | 'cancelled';        // Cancelled / refunded

export interface StatusHistoryItem {
  status: ReservationStatus;
  timestamp: string;
  noteAr: string;
  noteEn: string;
}

export interface Reservation {
  id: string;
  code: string;               // e.g. "RSV-849201"
  productId: string;
  productNameAr: string;
  productNameEn: string;
  productImage: string;
  totalPrice: number;
  depositAmount: number;      // Paid now via Vodafone Cash / InstaPay
  remainingAmount: number;    // Paid at the branch on pickup
  quantity: 1;                // Booking is always for exactly one piece
  customerName: string;
  customerPhone: string;
  customerEmail?: string;
  customerCity?: string;
  customerAddress?: string;
  customerNotes?: string;
  paymentMethod: PaymentMethod;
  paymentReferenceNumber?: string;
  paymentProofUrl?: string;               // Uploaded transfer screenshot (base64)
  proofSentOnWhatsApp?: boolean;          // Customer chose to send the screenshot on WhatsApp
  paymentStatus: PaymentStatus;
  reservationStatus: ReservationStatus;
  createdAt: string;
  estimatedDeliveryAr: string;
  estimatedDeliveryEn: string;
  merchantNotes?: string;
  history: StatusHistoryItem[];
}

export interface MerchantSettings {
  storeNameAr: string;
  storeNameEn: string;
  sloganAr: string;
  sloganEn: string;
  currencyAr: string;
  currencyEn: string;
  vodafoneCashNumbers: string[]; // One or more wallets the customer can transfer to
  instapayAddress: string;
  whatsappNumber: string;     // Receives transfer screenshots + floating chat button
  supportPhone: string;
  soundAlertEnabled: boolean;
  branchAddressAr: string;    // Where the customer picks up the order
  branchAddressEn: string;
  depositInstructionsAr: string;
  depositInstructionsEn: string;
  adminUsername: string;      // Login for the private admin dashboard
  adminPassword: string;
  announcementEnabled: boolean; // Fixed bar above the site header
  announcementAr: string;
  announcementEn: string;
  branchesTitleAr: string;     // e.g. "عنوان فروعنا💓"
  branches: BranchInfo[];      // Shown on the product details page
  branchPhones: string[];
}

export interface BranchInfo {
  name: string;
  address: string;
}

export interface NotificationItem {
  id: string;
  titleAr: string;
  titleEn: string;
  messageAr: string;
  messageEn: string;
  reservationCode: string;
  timestamp: string;
  isRead: boolean;
  type: 'new_booking' | 'payment_uploaded' | 'status_change';
}
