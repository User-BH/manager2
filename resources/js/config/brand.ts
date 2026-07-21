export const BRAND_NAME = 'ساکنا'
export const BRAND_TAGLINE = 'پنل مدیریت مجتمع مسکونی'

export const contactInfo = {
  address: 'تهران، خیابان ولیعصر، بالاتر از میدان ونک، برج نگین، طبقه ۴',
  phone: '۰۲۱-۸۸۷۷۶۶۵۵',
  email: 'info@sakena.app',
  mapEmbedUrl:
    'https://maps.google.com/maps?q=35.7595,51.4111&z=15&output=embed',
}

export const socialLinks = [
  {
    id: 'instagram',
    label: 'اینستاگرام',
    href: 'https://instagram.com/sakena.app',
    // گرادینت رسمی اینستاگرام
    hoverBackground: 'linear-gradient(45deg, #f9ce34, #ee2a7b, #6228d7)',
  },
  {
    id: 'telegram',
    label: 'تلگرام',
    href: 'https://t.me/sakena_app',
    hoverBackground: '#26A5E4',
  },
  {
    id: 'whatsapp',
    label: 'واتساپ',
    href: 'https://wa.me/982188776655',
    hoverBackground: '#25D366',
  },
  {
    id: 'rubika',
    label: 'روبیکا',
    href: 'https://rubika.ir/sakena_app',
    hoverBackground: '#F2461F',
  },
] as const
