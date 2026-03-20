FROM node:20-alpine

WORKDIR /app

# Install dependencies first (layer caching)
COPY frontend-react/package*.json ./
RUN npm ci --ignore-scripts 2>/dev/null || true

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
